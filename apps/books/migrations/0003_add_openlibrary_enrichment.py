from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ('books', '0002_add_book_fields'),
    ]

    operations = [
        migrations.AddField(
            model_name='book',
            name='openlibrary_work_key',
            field=models.CharField(blank=True, db_column='openlibrary_work_key', max_length=64, null=True),
        ),
        migrations.AddField(
            model_name='book',
            name='synopsis',
            field=models.TextField(blank=True, null=True),
        ),
        migrations.AddField(
            model_name='book',
            name='subjects',
            field=models.JSONField(blank=True, default=list),
        ),
        migrations.AddField(
            model_name='book',
            name='language_codes',
            field=models.JSONField(blank=True, db_column='language_codes', default=list),
        ),
        migrations.AddField(
            model_name='book',
            name='edition_count',
            field=models.PositiveIntegerField(db_column='edition_count', default=0),
        ),
        migrations.AddField(
            model_name='book',
            name='rating_average',
            field=models.FloatField(blank=True, db_column='rating_average', null=True),
        ),
        migrations.AddField(
            model_name='book',
            name='rating_count',
            field=models.PositiveIntegerField(db_column='rating_count', default=0),
        ),
        migrations.AddField(
            model_name='book',
            name='want_to_read_count',
            field=models.PositiveIntegerField(db_column='want_to_read_count', default=0),
        ),
        migrations.AddField(
            model_name='book',
            name='currently_reading_count',
            field=models.PositiveIntegerField(db_column='currently_reading_count', default=0),
        ),
        migrations.AddField(
            model_name='book',
            name='already_read_count',
            field=models.PositiveIntegerField(db_column='already_read_count', default=0),
        ),
    ]
