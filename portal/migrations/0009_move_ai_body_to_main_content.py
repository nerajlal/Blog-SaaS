from django.db import migrations
from django.db.models import F


def move_ai_bodies(apps, schema_editor):
    Blog = apps.get_model("portal", "Blog")
    Run = apps.get_model("portal", "AiRun")
    Section = apps.get_model("portal", "BlogSection")
    LinkEdit = apps.get_model("portal", "LinkEdit")
    db = schema_editor.connection.alias
    ids = (
        Run.objects.using(db)
        .filter(action="draft", output_blog_id__isnull=False)
        .values_list("output_blog_id", flat=True)
    )
    for blog in Blog.objects.using(db).filter(pk__in=ids, content="").iterator():
        sections = list(Section.objects.using(db).filter(blog_id=blog.pk))
        if len(sections) != 1:
            continue
        section = sections[0]
        if (
            not section.text_content.strip()
            or section.image_path
            or section.image_alt
            or section.image_caption
        ):
            continue
        if LinkEdit.objects.using(db).filter(section_id=section.pk).exists():
            continue
        moved = (
            Blog.objects.using(db)
            .filter(pk=blog.pk, content="")
            .update(content=section.text_content, revision=F("revision") + 1)
        )
        if moved:
            section.delete(using=db)


class Migration(migrations.Migration):
    dependencies = [("portal", "0008_hostedsite")]
    operations = [migrations.RunPython(move_ai_bodies, migrations.RunPython.noop)]
