from django import forms
from django.contrib.auth.forms import AuthenticationForm, UserCreationForm
from django.core.exceptions import ValidationError
from django.forms import inlineformset_factory
from django.utils import timezone
from .models import User, Blog, BlogSection, Project
from .ai import PROFILE_FIELDS


class LoginForm(AuthenticationForm):
    username = forms.EmailField(
        label="Email",
        widget=forms.EmailInput(
            attrs={"autocomplete": "email", "placeholder": "you@example.com"}
        ),
    )

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        if "password" in self.fields:
            self.fields["password"].widget.attrs.update(
                {"placeholder": "••••••••", "autocomplete": "current-password"}
            )

    def clean_username(self):
        email = self.cleaned_data["username"].lower()
        account = User.objects.filter(email__iexact=email).only("username").first()
        return account.username if account else email


class RegisterForm(UserCreationForm):
    email = forms.EmailField()
    first_name = forms.CharField(label="Your name", max_length=150)
    company_name = forms.CharField(required=False, max_length=255)

    class Meta:
        model = User
        fields = ["first_name", "email", "password1", "password2", "company_name"]

    def clean_email(self):
        email = self.cleaned_data["email"].lower()
        if User.objects.filter(email__iexact=email).exists():
            raise ValidationError("An account with this email already exists.")
        return email

    def save(self, commit=True):
        self.instance.username = self.cleaned_data["email"]
        self.instance.email = self.cleaned_data["email"]
        return super().save(commit)


class ProjectForm(forms.ModelForm):
    class Meta:
        model = Project
        fields = ["name", "description"]
        widgets = {"description": forms.TextInput()}


class AccountSettingsForm(forms.ModelForm):
    class Meta:
        model = User
        fields = ["first_name", "last_name"]
        labels = {"first_name": "First name", "last_name": "Last name"}


class AuthorProfileForm(forms.ModelForm):
    avatar_image = forms.ImageField(required=False, label="Author image")
    remove_avatar = forms.BooleanField(
        required=False, label="Remove current author image"
    )

    SOCIAL_PROFILES = [
        ("linkedin", "LinkedIn"),
        ("website", "Website"),
        ("facebook", "Facebook"),
        ("instagram", "Instagram"),
        ("x", "X / Twitter"),
        ("youtube", "YouTube"),
    ]

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.fields["author_bio"].max_length = 2000
        for key, label in self.SOCIAL_PROFILES:
            self.fields["social_" + key] = forms.URLField(
                required=False,
                max_length=2048,
                label=label + " profile URL",
                initial=(self.instance.author_social_links or {}).get(key, ""),
                widget=forms.URLInput(attrs={"placeholder": "https://…"}),
            )

    def clean_author_bio(self):
        bio = self.cleaned_data.get("author_bio", "").strip()
        if len(bio) > 2000:
            raise ValidationError(
                "Keep your author description under 2,000 characters."
            )
        return bio

    def clean(self):
        data = super().clean()
        if data.get("avatar_image") and data.get("remove_avatar"):
            self.add_error(
                "avatar_image",
                "Upload a new image or remove the current one, not both.",
            )
        from .services import public_url

        for key, _ in self.SOCIAL_PROFILES:
            field = "social_" + key
            if data.get(field):
                try:
                    url = public_url(data[field])
                    if not url.startswith("https://"):
                        raise ValidationError("Use an HTTPS profile link.")
                    data[field] = url
                except (ValidationError, ValueError) as error:
                    self.add_error(
                        field,
                        error
                        if isinstance(error, ValidationError)
                        else "Enter a valid public HTTPS URL.",
                    )
        return data

    def save(self, commit=True):
        user = super().save(commit=False)
        user.author_social_links = {
            key: self.cleaned_data["social_" + key]
            for key, _ in self.SOCIAL_PROFILES
            if self.cleaned_data.get("social_" + key)
        }
        if commit:
            user.save()
        return user

    class Meta:
        model = User
        fields = [
            "author_name",
            "author_title",
            "author_bio",
            "avatar_image",
            "remove_avatar",
        ]
        labels = {
            "author_name": "Author name",
            "author_title": "Author title / role",
            "author_bio": "Author description",
        }
        widgets = {"author_bio": forms.Textarea(attrs={"rows": 3, "maxlength": 2000})}


class BlogForm(forms.ModelForm):
    slug = forms.SlugField(
        required=False,
        max_length=255,
        label="Article slug",
        help_text="Use letters, numbers, hyphens or underscores. Leave blank for an automatic slug. Changing a published slug changes its API URL; update links and redirects on your website.",
    )
    editor_revision = forms.CharField(required=False, widget=forms.HiddenInput)
    banner_image = forms.ImageField(required=False)
    avatar_image = forms.ImageField(required=False)
    seo_title = forms.CharField(required=False, max_length=255)
    seo_description = forms.CharField(
        required=False, max_length=500, widget=forms.Textarea(attrs={"rows": 2})
    )
    focus_keyphrase = forms.CharField(required=False, max_length=120)
    robots_index = forms.ChoiceField(
        choices=[("inherit", "Inherit"), ("index", "Index"), ("noindex", "No index")],
        required=False,
    )
    canonical_url = forms.URLField(required=False, max_length=2048)
    robots_follow = forms.ChoiceField(
        required=False,
        choices=[
            ("inherit", "Inherit"),
            ("follow", "Follow"),
            ("nofollow", "No follow"),
        ],
    )
    schema_type = forms.ChoiceField(
        required=False,
        choices=[
            ("BlogPosting", "BlogPosting"),
            ("Article", "Article"),
            ("none", "None"),
        ],
    )
    og_title = forms.CharField(required=False, max_length=255, label="Social title")
    og_description = forms.CharField(
        required=False,
        max_length=500,
        label="Social description",
        widget=forms.Textarea(attrs={"rows": 2}),
    )
    og_image_url = forms.URLField(
        required=False, max_length=2048, label="Social image URL"
    )
    x_title = forms.CharField(required=False, max_length=255, label="X title")
    x_description = forms.CharField(
        required=False,
        max_length=500,
        label="X description",
        widget=forms.Textarea(attrs={"rows": 2}),
    )
    x_image_url = forms.URLField(required=False, max_length=2048, label="X image URL")

    class Meta:
        model = Blog
        fields = [
            "title",
            "slug",
            "category",
            "excerpt",
            "content",
            "status",
            "published_at",
            "banner_image",
            "cover_image_alt",
            "cover_image_caption",
            "cover_image_decorative",
            "image_credits",
            "author_name",
            "author_title",
            "avatar_image",
            "is_cornerstone",
            "seo_title",
            "seo_description",
            "focus_keyphrase",
            "robots_index",
            "canonical_url",
            "robots_follow",
            "schema_type",
            "og_title",
            "og_description",
            "og_image_url",
            "x_title",
            "x_description",
            "x_image_url",
            "editor_revision",
        ]
        widgets = {
            "content": forms.Textarea(attrs={"class": "rich-editor", "rows": 10}),
            "published_at": forms.DateTimeInput(attrs={"type": "datetime-local"}),
            "excerpt": forms.Textarea(attrs={"rows": 2}),
            "cover_image_caption": forms.Textarea(attrs={"rows": 2}),
        }

    def __init__(self, *args, project, **kwargs):
        super().__init__(*args, **kwargs)
        if not self.instance.pk:
            self.initial["status"] = "published"
        for name, label in {
            "banner_image": "Featured image",
            "cover_image_alt": "Image alt text",
            "cover_image_caption": "Image caption",
            "image_credits": "Image credits",
        }.items():
            self.fields[name].label = label
        self.fields["category"].queryset = project.categories.all()
        self.fields["category"].required = True
        if not self.instance.pk and self.instance.user_id:
            self.initial["author_name"] = (
                self.instance.user.author_name or self.instance.user.first_name
            )
            self.initial["author_title"] = self.instance.user.author_title
        for field, key in [
            ("seo_title", "title"),
            ("seo_description", "description"),
            ("focus_keyphrase", "focus_keyphrase"),
            ("canonical_url", "canonical_url"),
        ]:
            self.initial[field] = self.instance.seo_settings.get(key, "")
        self.initial["robots_index"] = self.instance.seo_settings.get(
            "robots_index", "inherit"
        )
        for key in [
            "robots_follow",
            "schema_type",
            "og_title",
            "og_description",
            "og_image_url",
            "x_title",
            "x_description",
            "x_image_url",
        ]:
            self.initial[key] = self.instance.seo_settings.get(
                key,
                "inherit"
                if key == "robots_follow"
                else "BlogPosting"
                if key == "schema_type"
                else "",
            )

    def clean_slug(self):
        value = self.cleaned_data.get("slug", "")
        if not value and self.instance.pk:
            return Blog.objects.get(pk=self.instance.pk).slug
        return value

    def clean(self):
        data = super().clean()
        if data.get("status") == "scheduled" and (
            not data.get("published_at") or data["published_at"] <= timezone.now()
        ):
            self.add_error("published_at", "Choose a future publication date.")
        if data.get("status") == "draft":
            data["published_at"] = None
        elif data.get("status") == "published":
            previous_date = self.instance.published_at
            data["published_at"] = (
                previous_date
                if previous_date and previous_date <= timezone.now()
                else None
            )
        from .services import public_url

        for key in ["canonical_url", "og_image_url", "x_image_url"]:
            if data.get(key):
                try:
                    if not public_url(data[key]).startswith("https:"):
                        raise ValidationError("Use a public HTTPS URL.")
                except (ValidationError, ValueError):
                    self.add_error(key, "Use a public HTTPS URL on standard ports.")
        return data


class SectionForm(forms.ModelForm):
    image = forms.ImageField(required=False)

    class Meta:
        model = BlogSection
        fields = [
            "text_content",
            "image",
            "image_alt",
            "image_caption",
            "image_decorative",
            "order",
        ]
        widgets = {
            "text_content": forms.Textarea(attrs={"class": "rich-editor", "rows": 6}),
            "image_caption": forms.Textarea(attrs={"rows": 2}),
        }


SectionFormSet = inlineformset_factory(
    Blog,
    BlogSection,
    form=SectionForm,
    extra=0,
    can_delete=True,
    max_num=100,
    validate_max=True,
    min_num=0,
    validate_min=False,
)


class ProfileForm(forms.Form):
    revision = forms.IntegerField(min_value=0, widget=forms.HiddenInput)

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        for key, label in PROFILE_FIELDS.items():
            self.fields[key] = forms.CharField(
                label=label,
                required=key in ["name", "summary", "audience"],
                max_length=4000,
                widget=forms.TextInput
                if key in ["name", "website", "language", "business_type"]
                else forms.Textarea(attrs={"rows": 3}),
            )

    def clean(self):
        import json

        data = super().clean()
        if len(json.dumps(data).encode()) > 18000:
            raise ValidationError("Keep the profile under 18 KB.")
        return data


class SeoForm(forms.Form):
    public_url = forms.URLField(
        required=False,
        label="Your website URL",
        help_text="The website where visitors read your blog, for example https://example.com.",
    )
    blog_path = forms.CharField(
        initial="/blog/{slug}",
        max_length=500,
        label="Article URL path",
        help_text="Match your website route, for example /blog/{slug}. Include {slug} exactly once.",
    )

    def clean_blog_path(self):
        from .services import rooted_path

        path = self.cleaned_data["blog_path"]
        if path.count("{slug}") != 1:
            raise ValidationError("Include {slug} exactly once.")
        rooted_path(path.replace("{slug}", "story"))
        return path


class AiRequestForm(forms.Form):
    action = forms.ChoiceField(
        choices=[
            (x, x.title())
            for x in [
                "draft",
                "outline",
                "social",
                "titles",
                "metadata",
                "rewrite",
                "analyze",
            ]
        ]
    )
    request_key = forms.UUIDField(widget=forms.HiddenInput)
    brief = forms.CharField(
        required=False, max_length=4000, widget=forms.Textarea(attrs={"rows": 3})
    )
    preferences = forms.CharField(
        required=False, max_length=2000, widget=forms.Textarea(attrs={"rows": 2})
    )
    selected_text = forms.CharField(
        required=False, max_length=8000, widget=forms.Textarea(attrs={"rows": 3})
    )
    platform = forms.ChoiceField(
        required=False,
        choices=[("", "Choose a platform")]
        + [
            ("linkedin", "LinkedIn"),
            ("reddit", "Reddit"),
            ("facebook", "Facebook"),
            ("instagram", "Instagram"),
        ],
    )
    community_rules = forms.CharField(
        required=False, max_length=4000, widget=forms.Textarea(attrs={"rows": 3})
    )
    public_url = forms.URLField(required=False)
    urls = forms.CharField(
        required=False,
        max_length=6200,
        label="Website pages, one URL per line (up to three)",
        widget=forms.Textarea(attrs={"rows": 3}),
    )
    source_text = forms.CharField(
        required=False, max_length=12000, widget=forms.Textarea(attrs={"rows": 4})
    )
