
# Copilot Workspace Instructions

## General Coding Rules
- Write all code and comments in English.
- Use PHP 8.4 or newer.
- Follow PSR-12 coding standard for all PHP code.
- Use meaningful variable names; avoid magic numbers/strings—define constants instead.
- Always include type hints for function parameters and return types.
- Add docblocks to every function and class, describing purpose, parameters, and return types.
- Handle exceptions properly and provide clear, actionable error messages.
- Use the i18n library for all translatable strings (wrap with _()).
- Never expose sensitive information in code or comments.
- Optimize for performance and maintain compatibility with latest PHP and libraries.
- Ensure code is maintainable and follows best practices.

## Testing & Quality
- Use PHPUnit for all tests; follow PSR-12 in test code.
- When creating or updating a class, also create or update its PHPUnit test file.
- After every PHP file edit, run `php -l` to lint and ensure code sanity before proceeding further (mandatory).

## Documentation & Commits
- Write documentation in Markdown format.
- Use imperative mood for commit messages; keep them concise and relevant.

## MultiFlexi Integration
- All files in `multiflexi/*.app.json` must conform to the schema: https://raw.githubusercontent.com/VitexSoftware/php-vitexsoftware-multiflexi

## Copilot Guidance
- Prefer clear, concise, and actionable suggestions.
- When generating code, always follow the above rules.
- When updating or creating files, ensure all requirements are met before marking a task complete.

