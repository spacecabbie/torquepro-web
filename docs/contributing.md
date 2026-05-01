# Contributing & Transparency

Thank you for your interest in contributing to the Torque Pro Web Logger!

---

## How to Contribute

1. **Fork** the repository
2. **Create a feature branch** (`git checkout -b feature/your-feature-name`)
3. **Make your changes** following PSR-12 coding standards
4. **Add clear comments** explaining any non-obvious design decisions
5. **Update `history.md`** with a short description of your change
6. **Open a Pull Request**

---

## Code Style Guidelines

- Use **strict types** everywhere (`declare(strict_types=1)`)
- Follow **PSR-12** formatting
- Prefer **early returns** and **guard clauses**
- Keep functions focused and small
- Add **inline comments** for complex logic (especially in `parser.php`)

---

## Transparency Policy

This project is developed with **maximum transparency** in mind.

As an AI pair programmer, all AI prompts, implementation reasoning, and architectural decisions are documented in `PROGRESS.md` and `history.md`. GitHub already tracks every code change, so these files focus on the "why" and "how" behind the decisions.

All of this is recorded in:

- [`PROGRESS.md`](../PROGRESS.md)
- [`history.md`](../history.md)

When you contribute, please continue this tradition by adding a brief entry to `history.md`.

---

## Areas Where Help is Welcome

- Improving the dashboard UI/UX
- Adding support for additional Android logging apps
- Writing better documentation and examples
- Performance optimizations for very large datasets
- Adding unit tests (currently minimal)
- Internationalization / multi-language support

---

## Questions?

Feel free to open an issue with the label `question` or `discussion`.

We value thoughtful discussion and are happy to explain the reasoning behind any part of the codebase.

---

**Thank you for helping make this project better!**
