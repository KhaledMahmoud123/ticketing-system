# Ticketing Package

## Install

1. Require or clone the package into your Laravel app.
2. Ensure the package provider is discovered or registered.
3. Publish config and migrations.
4. Run migrations.

```bash
php artisan vendor:publish --tag=ticketing --force
php artisan migrate
```

## Configuration

Edit `config/ticketing.php` after publishing.

- `models.user` sets the host user model used by ticket relations.
- `owner.request_keys` controls how the student-facing API reads owner type and ID from the request.
- `owner.type_map` maps incoming owner type values to host model classes.
- `access.field` controls which ticket type column gates visibility.
- `access.manage_permission` and `access.super_admin_permission` control the default access resolver.

## Custom role or permission systems

Bind your own implementation of `Khaled\Ticketing\Contracts\TicketAccessResolver` in the host app container.

```php
use Khaled\Ticketing\Contracts\TicketAccessResolver;

$this->app->bind(TicketAccessResolver::class, \App\Support\CustomTicketAccessResolver::class);
```

Your resolver can read roles, permissions, tenants, departments, or any other host-specific access system.

## Custom owner resolution

Bind your own implementation of `Khaled\Ticketing\Contracts\TicketOwnerResolver` if your project resolves ticket owners differently.

```php
use Khaled\Ticketing\Contracts\TicketOwnerResolver;

$this->app->bind(TicketOwnerResolver::class, \App\Support\CustomTicketOwnerResolver::class);
```

This is the main extension point for projects that do not use the package's default token-to-model mapping.
