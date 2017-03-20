# content_translation_worfklow

Let's assume you have enabled workbench_moderation + multilingual content.
Now you've some published content in multiple languages.

Without this module you are not able to create published versions in just one language, without unpublishing the others.

## NOTE
This is a GH module by @dawehner. If he makes it a full project, we can just put
it in our composer.json file and make it a dependency of lightning_workflow.
Otherwise we'll rename this `lightning_workflow_translation_forward_revisions` -
or something like that - and keep it our codebase.

This is just a stop-gap until we can migrate to core Content Moderation.
