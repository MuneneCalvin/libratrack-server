from django.db import models
from apps.auth_app.models import User


class Member(models.Model):
    user = models.OneToOneField(User, on_delete=models.CASCADE, related_name='member', db_column='user_id')
    full_name = models.CharField(max_length=255, db_column='full_name')
    phone = models.CharField(max_length=50, null=True, blank=True)
    address = models.TextField(null=True, blank=True)
    membership_number = models.CharField(max_length=50, unique=True, db_column='membership_number')
    joined_at = models.DateTimeField(auto_now_add=True, db_column='joined_at')

    class Meta:
        db_table = 'members'

    def __str__(self):
        return self.full_name
