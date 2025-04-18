# Database Migrations Standards

## Introduction

This document outlines the standards and best practices for creating database migrations in our Laravel application. Following these guidelines ensures consistency across our database schema, optimizes performance, and maintains data integrity. This document is essential for developers who need to create or modify database tables.

### Key Standards Summary:

- Use ULIDs for primary keys, not auto-incrementing integers or UUIDs
- Store enums as strings in the database and cast to PHP enum types in models
- Be explicit about foreign key relationships and cascade behaviors
- Follow consistent naming conventions for all database objects
- Choose appropriate column types based on content needs
- Never use `$fillable` in models as the project uses `Model::unguard()`

