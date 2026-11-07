from django.contrib.auth.hashers import BCryptPasswordHasher
import bcrypt


class LaravelBcryptHasher(BCryptPasswordHasher):
    """Read Laravel's legacy bcrypt format; new passwords use Django PBKDF2."""

    def verify(self, password, encoded):
        try:
            algorithm, hashed = encoded.split("$", 1)
            # PHP bcrypt historically truncates at 72 bytes, including long inputs.
            return algorithm == self.algorithm and bcrypt.checkpw(
                password.encode()[:72], hashed.encode()
            )
        except (ValueError, TypeError):
            return False
