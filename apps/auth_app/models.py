import bcrypt as _bcrypt

from django.contrib.auth.models import AbstractBaseUser, BaseUserManager
from django.db import models


class Role(models.Model):
    name = models.CharField(max_length=50, unique=True)

    class Meta:
        db_table = 'roles'

    def __str__(self):
        return self.name


class UserManager(BaseUserManager):
    def create_user(self, email, role, password=None, **extra_fields):
        if not email:
            raise ValueError('Email is required')
        user = self.model(email=self.normalize_email(email), role=role, **extra_fields)
        if password:
            user.set_password(password)
        user.save(using=self._db)
        return user

    def create_superuser(self, email, password, **extra_fields):
        raise NotImplementedError('Use create_user with admin role instead')


class User(AbstractBaseUser):
    # Override AbstractBaseUser's password field to use db_column='password_hash'
    password = models.CharField(max_length=255, db_column='password_hash')
    email = models.EmailField(unique=True)
    role = models.ForeignKey(Role, on_delete=models.PROTECT, related_name='users', db_column='role_id')
    is_active = models.BooleanField(default=True)
    must_change_password = models.BooleanField(default=False)
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    USERNAME_FIELD = 'email'
    REQUIRED_FIELDS = []
    objects = UserManager()

    class Meta:
        db_table = 'users'

    def __str__(self):
        return self.email

    def check_password(self, raw_password):
        try:
            secret = raw_password.encode('utf-8') if isinstance(raw_password, str) else raw_password
            stored = self.password.encode('utf-8') if isinstance(self.password, str) else self.password
            return _bcrypt.checkpw(secret, stored)
        except Exception:
            return False

    def set_password(self, raw_password):
        if raw_password:
            secret = raw_password.encode('utf-8') if isinstance(raw_password, str) else raw_password
            self.password = _bcrypt.hashpw(secret, _bcrypt.gensalt(rounds=12)).decode('utf-8')

    def has_perm(self, perm, obj=None):
        return True

    def has_module_perms(self, app_label):
        return True

    @property
    def member_id(self):
        try:
            return self.member.id
        except Exception:
            return None


class RefreshToken(models.Model):
    user = models.ForeignKey(User, on_delete=models.CASCADE, related_name='refresh_tokens', db_column='user_id')
    token_hash = models.CharField(max_length=255, unique=True)
    expires_at = models.DateTimeField()
    revoked_at = models.DateTimeField(null=True, blank=True)

    class Meta:
        db_table = 'refresh_tokens'
