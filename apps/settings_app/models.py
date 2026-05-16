from django.db import models


class AppSetting(models.Model):
    key = models.CharField(max_length=100, unique=True)
    value = models.CharField(max_length=255)

    class Meta:
        db_table = 'app_settings'

    def __str__(self):
        return f'{self.key}={self.value}'
