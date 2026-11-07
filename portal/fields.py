import base64
import hashlib
import json
from cryptography.fernet import Fernet
from django.conf import settings
from django.db import models


class EncryptedJSONField(models.TextField):
    def from_db_value(self, value, expression, connection):
        return self.to_python(value)

    def to_python(self, value):
        if value is None or isinstance(value, (dict, list)):
            return value
        key = base64.urlsafe_b64encode(
            hashlib.sha256(settings.ENCRYPTION_KEY.encode()).digest()
        )
        return json.loads(Fernet(key).decrypt(value.encode()))

    def get_prep_value(self, value):
        if value is None:
            return None
        key = base64.urlsafe_b64encode(
            hashlib.sha256(settings.ENCRYPTION_KEY.encode()).digest()
        )
        return Fernet(key).encrypt(json.dumps(value).encode()).decode()
