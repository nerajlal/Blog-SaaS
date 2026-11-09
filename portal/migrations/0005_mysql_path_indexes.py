"""Upgrade databases created before the MySQL-safe initial schema correction.

0001 also needs the correction: MySQL cannot reach a later migration while
creating its original >3072-byte unique indexes. Preserve full path values.
"""

import hashlib
from django.db import migrations


def upgrade(apps, schema_editor):
    connection = schema_editor.connection
    for name, path, hashed, old, new in [
        (
            "RedirectRule",
            "source_path",
            "source_hash",
            "unique_redirect",
            "unique_redirect_hash",
        ),
        ("NotFoundEvent", "path", "path_hash", "unique_404_day", "unique_404_day_hash"),
    ]:
        model = apps.get_model("portal", name)
        table = model._meta.db_table
        with connection.cursor() as cursor:
            columns = {
                column.name
                for column in connection.introspection.get_table_description(
                    cursor, table
                )
            }
        if hashed not in columns:
            field = model._meta.get_field(hashed).clone()
            field.set_attributes_from_name(hashed)
            field.model = model
            field.null = True
            schema_editor.add_field(model, field)
            for row in model.objects.using(connection.alias).all().iterator():
                model.objects.using(connection.alias).filter(pk=row.pk).update(
                    **{
                        hashed: hashlib.sha256(
                            getattr(row, path).encode("utf-8")
                        ).hexdigest()
                    }
                )
            # Existing Django SQLite deployments have the original constraint;
            # remove it before rebuilding a table using the corrected state.
            with connection.cursor() as cursor:
                constraints = connection.introspection.get_constraints(cursor, table)
            if old in constraints:
                from django.db import models

                fields = ["project", path] + (
                    ["day"] if name == "NotFoundEvent" else []
                )
                schema_editor.remove_constraint(
                    model, models.UniqueConstraint(fields=fields, name=old)
                )
            schema_editor.alter_field(model, field, model._meta.get_field(hashed))
        with connection.cursor() as cursor:
            constraints = connection.introspection.get_constraints(cursor, table)
        if new not in constraints:
            constraint = next(c for c in model._meta.constraints if c.name == new)
            schema_editor.add_constraint(model, constraint)


class Migration(migrations.Migration):
    dependencies = [("portal", "0004_analyticsconnection")]
    operations = [migrations.RunPython(upgrade, migrations.RunPython.noop)]
