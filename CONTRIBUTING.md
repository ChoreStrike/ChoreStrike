# Contributing to ChoreStrike

Thank you for your interest in contributing to ChoreStrike! This document provides guidelines and information for contributors.

## 🌟 How to Contribute

### Reporting Bugs
- Use the GitHub issue tracker
- Describe the bug clearly with steps to reproduce
- Include your Nextcloud version and browser information
- Add screenshots if applicable

### Suggesting Features
- Check if the feature has already been suggested
- Describe the use case and expected behavior
- Explain how it fits with ChoreStrike' philosophy

### Code Contributions

1. **Fork the repository**
2. **Create a feature branch** (`git checkout -b feature/amazing-feature`)
3. **Follow the coding standards** (see below)
4. **Test your changes**
5. **Commit with clear messages** (`git commit -m 'Add amazing feature'`)
6. **Push to your fork** (`git push origin feature/amazing-feature`)
7. **Open a Pull Request**

## 📝 Coding Standards

### PHP (Backend)
- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standard
- Use type hints where possible
- Document all public methods with PHPDoc
- Keep methods focused and small

### JavaScript/Vue.js (Frontend)
- Use ES6+ syntax
- Follow Vue.js style guide
- Use meaningful variable and function names
- Add JSDoc comments for complex functions

### CSS
- Use BEM naming convention where applicable
- Keep styles modular and reusable
- Prefer CSS variables for theming

## 🌍 Translations

We welcome translations! To add a new language:

1. Copy `l10n/templates/chorestrike.pot` to `l10n/[language_code].po`
2. Translate all strings
3. Test the translation in the app
4. Submit a pull request

## 🎨 Design Contributions

- Sprite designs should be pixel art style
- Keep animations lightweight (performance matters)
- Maintain the "cute but not childish" aesthetic
- Provide assets in multiple sizes if possible

## 🧪 Testing

- Test on different browsers (Chrome, Firefox, Safari)
- Test on mobile devices
- Verify multi-user scenarios
- Check different Nextcloud versions

## 📋 Pull Request Checklist

- [ ] Code follows project style guidelines
- [ ] Comments added for complex logic
- [ ] Documentation updated if needed
- [ ] No console errors or warnings
- [ ] Tested on latest Nextcloud version
- [ ] Translation strings marked for i18n
- [ ] Commit messages are clear and descriptive

## 🤔 Questions?

Feel free to open an issue for questions or join our discussions.

## 📜 Code of Conduct

Be respectful, inclusive, and constructive. We're all here to make household chores more fun!

---

Thank you for contributing to ChoreStrike! 🎮✨
