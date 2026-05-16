import django.utils.timezone
from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ('books', '0001_initial'),
    ]

    operations = [
        migrations.AddField(
            model_name='book',
            name='publisher',
            field=models.CharField(blank=True, max_length=255, null=True),
        ),
        migrations.AddField(
            model_name='book',
            name='published_year',
            field=models.IntegerField(blank=True, db_column='published_year', null=True),
        ),
        migrations.AddField(
            model_name='book',
            name='cover_url',
            field=models.CharField(blank=True, db_column='cover_url', max_length=500, null=True),
        ),
        migrations.AddField(
            model_name='book',
            name='created_at',
            field=models.DateTimeField(auto_now_add=True, default=django.utils.timezone.now),
            preserve_default=False,
        ),
        migrations.AddField(
            model_name='book',
            name='updated_at',
            field=models.DateTimeField(auto_now=True),
        ),
        migrations.AlterField(
            model_name='book',
            name='title',
            field=models.CharField(max_length=500),
        ),
        migrations.AlterField(
            model_name='book',
            name='author',
            field=models.CharField(max_length=500),
        ),
        migrations.AlterField(
            model_name='book',
            name='total_copies',
            field=models.IntegerField(db_column='total_copies', default=1),
        ),
        migrations.AlterField(
            model_name='book',
            name='available_copies',
            field=models.IntegerField(db_column='available_copies', default=1),
        ),
    ]
