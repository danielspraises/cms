Add this line to `bootstrap/providers.php` in the fresh project:

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,  // <-- add this
];
```
