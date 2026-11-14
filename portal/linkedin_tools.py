import json
import logging
import urllib.parse
from datetime import timedelta
import requests
from django.conf import settings
from django.utils import timezone
from .models import LinkedInConnection

logger = logging.getLogger(__name__)

LINKEDIN_AUTH_URL = "https://www.linkedin.com/oauth/v2/authorization"
LINKEDIN_TOKEN_URL = "https://www.linkedin.com/oauth/v2/accessToken"
LINKEDIN_USERINFO_URL = "https://api.linkedin.com/v2/userinfo"
LINKEDIN_REST_POSTS_URL = "https://api.linkedin.com/rest/posts"
LINKEDIN_API_VERSION = "202401"


def get_authorization_url(state: str, redirect_uri: str = None) -> str:
    """Generate the LinkedIn OAuth 2.0 authorization URL."""
    client_id = settings.LINKEDIN_CLIENT_ID
    redirect_uri = redirect_uri or settings.LINKEDIN_REDIRECT_URI
    # Standard requested scopes: openid, profile, email, w_member_social
    scopes = "openid profile email w_member_social"
    params = {
        "response_type": "code",
        "client_id": client_id,
        "redirect_uri": redirect_uri,
        "state": state,
        "scope": scopes,
    }
    return f"{LINKEDIN_AUTH_URL}?{urllib.parse.urlencode(params)}"


def exchange_code_for_token(code: str, redirect_uri: str = None) -> dict:
    """Exchange authorization code for an access token."""
    redirect_uri = redirect_uri or settings.LINKEDIN_REDIRECT_URI
    payload = {
        "grant_type": "authorization_code",
        "code": code,
        "redirect_uri": redirect_uri,
        "client_id": settings.LINKEDIN_CLIENT_ID,
        "client_secret": settings.LINKEDIN_CLIENT_SECRET,
    }
    resp = requests.post(LINKEDIN_TOKEN_URL, data=payload, timeout=15)
    resp.raise_for_status()
    return resp.json()


def fetch_user_info(access_token: str) -> dict:
    """Fetch user profile via OIDC UserInfo endpoint."""
    headers = {"Authorization": f"Bearer {access_token}"}
    resp = requests.get(LINKEDIN_USERINFO_URL, headers=headers, timeout=10)
    resp.raise_for_status()
    return resp.json()


def fetch_administered_organizations(access_token: str) -> list:
    """
    Fetch organizations/pages administered by the user (if organizational permissions are granted).
    Returns a list of dicts: [{'urn': 'urn:li:organization:123', 'name': '...'}]
    """
    url = "https://api.linkedin.com/rest/organizationalEntityAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED"
    headers = {
        "Authorization": f"Bearer {access_token}",
        "LinkedIn-Version": LINKEDIN_API_VERSION,
        "X-Restli-Protocol-Version": "2.0.0",
    }
    pages = []
    try:
        resp = requests.get(url, headers=headers, timeout=10)
        if resp.status_code == 200:
            data = resp.json()
            for element in data.get("elements", []):
                org_urn = element.get("organizationalTarget")
                if org_urn:
                    # Try to fetch org name
                    org_id = org_urn.split(":")[-1]
                    name = f"Organization {org_id}"
                    try:
                        org_resp = requests.get(
                            f"https://api.linkedin.com/rest/organizations/{org_id}",
                            headers=headers,
                            timeout=5,
                        )
                        if org_resp.status_code == 200:
                            org_data = org_resp.json()
                            name = (
                                org_data.get("localizedName")
                                or org_data.get("vanityName")
                                or name
                            )
                    except Exception:
                        pass
                    pages.append({"urn": org_urn, "name": name})
    except Exception as exc:
        logger.warning("Could not fetch LinkedIn organizations: %s", exc)
    return pages


def save_or_update_connection(user, token_data: dict) -> LinkedInConnection:
    """Save or update LinkedIn connection with user info and administered pages."""
    access_token = token_data.get("access_token")
    expires_in = token_data.get("expires_in", 5184000)  # default 60 days
    expires_at = timezone.now() + timedelta(seconds=expires_in)

    user_info = fetch_user_info(access_token)
    person_sub = user_info.get("sub")
    name = user_info.get("name") or f"{user_info.get('given_name', '')} {user_info.get('family_name', '')}".strip()
    picture = user_info.get("picture", "")
    author_urn = f"urn:li:person:{person_sub}" if person_sub else ""

    # Fetch any administered company pages
    pages = fetch_administered_organizations(access_token)

    connection, _ = LinkedInConnection.objects.get_or_create(user=user)
    connection.tokens = token_data
    connection.account_urn = author_urn
    connection.account_name = name or "LinkedIn User"
    connection.account_picture = picture
    connection.pages = pages
    if not connection.selected_target or connection.selected_target not in [p["urn"] for p in pages]:
        connection.selected_target = author_urn
    connection.expires_at = expires_at
    connection.save()
    return connection


def create_post(connection: LinkedInConnection, text: str, target_urn: str = None, media_files: list = None) -> dict:
    """
    Publish a post directly to LinkedIn via REST API (/rest/posts).
    target_urn: person or organization URN (defaults to connection.selected_target or person urn).
    """
    access_token = connection.tokens.get("access_token")
    if not access_token:
        raise ValueError("LinkedIn connection is not authenticated.")

    author = target_urn or connection.selected_target or connection.account_urn
    if not author:
        raise ValueError("No LinkedIn author / page target specified.")

    headers = {
        "Authorization": f"Bearer {access_token}",
        "Content-Type": "application/json",
        "LinkedIn-Version": LINKEDIN_API_VERSION,
        "X-Restli-Protocol-Version": "2.0.0",
    }

    payload = {
        "author": author,
        "commentary": text,
        "visibility": "PUBLIC",
        "distribution": {
            "feedDistribution": "MAIN_FEED",
            "targetEntities": [],
            "thirdPartyDistributionChannels": []
        },
        "lifecycleState": "PUBLISHED",
        "isReshareDisabledByAuthor": False
    }

    resp = requests.post(
        LINKEDIN_REST_POSTS_URL,
        headers=headers,
        data=json.dumps(payload),
        timeout=20,
    )

    if resp.status_code in (200, 201):
        post_urn = resp.headers.get("x-restli-id") or ""
        return {"success": True, "post_urn": post_urn, "status_code": resp.status_code}
    
    # Try reading error message
    error_detail = resp.text
    try:
        err_json = resp.json()
        error_detail = err_json.get("message") or err_json.get("error_description") or resp.text
    except Exception:
        pass

    logger.error("LinkedIn post error: %d - %s", resp.status_code, resp.text)
    return {"success": False, "error": error_detail, "status_code": resp.status_code}
