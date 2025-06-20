# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Communauté RNF is a Symfony 4.4 LTS collaborative platform dedicated to the French Natural Reserves network (Réserves Naturelles de France - RNF). It enables users to create groups, share articles, discussions, documents, and collaborate around natural reserve management and commission activities.

## Essential Commands

### Development Setup
```bash
# Backend setup
composer install
cp .env .env.local  # Configure database credentials
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load  # Load test data
php bin/console import:skills  # Import default skills

# Frontend setup
npm install
npm run watch  # Development build with watch
npm run build  # Production build
```

### Testing
```bash
npm run test  # Runs database setup and PHPUnit tests
```

### Common Development Tasks
```bash
# Clear Symfony cache
php bin/console cache:clear

# Update database schema
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate

# User management
php bin/console user:activate <email>
php bin/console user:deactivate <email>
php bin/console user:set-admin <email>
php bin/console user:unset-admin <email>

# Search index management
php bin/console search:reindex:all
php bin/console search:reindex <entity>

# Update geographic data
php bin/console app:update-coordinates
php bin/console app:update-nuts-id
```

## Architecture Overview

### Core Entities and Relationships
- **User**: Central entity with profiles, skills, and geographic data
- **Usergroup**: Groups with different access levels (public, open, moderate, restricted)
- **UsergroupMembership**: Links users to groups with roles (member, admin)
- **Content Types**: Article, Discussion, Document, Page - all linked to groups
- **File/Upload**: Managed file uploads with security constraints

### Security Model
- **Authentication**: Form-based login with email/password
- **Authorization**: Voter-based system for fine-grained permissions
- **Group Access Levels**:
  - PUBLIC: Anyone can view and join
  - OPEN: Authenticated users can view and join
  - MODERATE: Join requests require approval
  - RESTRICTED: Invisible to non-members

### Service Layer Architecture
Key services handle business logic:
- `FileManager`: Central file handling with security checks
- `UserGroupRelation`: Manages user-group relationships
- `EmailSender`: Handles all email notifications via Postmark
- `SearchEngineManager`: TNTSearch integration for full-text search
- `Community`: Manages the general community group

### Frontend Architecture
- **Asset Management**: Webpack Encore with SCSS and ES6
- **JavaScript Organization**: Feature-based modules in `assets/js/`
- **Map Integration**: Leaflet for geographic visualization
- **WYSIWYG**: CKEditor 5 for rich text editing

## Key Configuration

### Environment Variables (.env.local)
```bash
DATABASE_URL=mysql://user:pass@127.0.0.1:3306/naturadapt
MAILER_URL=postmark+api://API_KEY@default
COMMUNITY_SLUG=communaute-fr  # General community identifier
SECURE_SCHEME=https  # Force HTTPS
TRUSTED_PROXIES=127.0.0.1  # For proxy setups
```

### Platform Configuration
Platform settings are stored in the database and managed via admin interface:
- Site name, description, contact
- Menu configuration
- Homepage content
- Links and resources

## Development Guidelines

### File Upload Handling
- All uploads go through `FileManager` service
- Files are stored in `var/uploads/` with UUID-based paths
- Security checks prevent directory traversal
- Automatic image optimization via LiipImagineBundle

### Search Implementation
- Uses TNTSearch for full-text search
- Indexes stored in `var/indexes/`
- Automatic reindexing on entity changes via event subscribers
- Searchable entities implement specific repository traits

### Email Notifications
- All emails use Postmark transport
- Templates in `templates/emails/`
- Key notifications: registration, group requests, discussions
- Bulk sending supported for group notifications

### Frontend Development
- SCSS files in `assets/css/` follow component structure
- JavaScript modules use ES6 syntax
- Map components require Leaflet initialization
- Form enhancements via Symfony UX components