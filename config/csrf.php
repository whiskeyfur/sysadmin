<?php

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
|
| Merged into Leaf MVC's CSRF settings. The app's scripts take the token from
| the page's hidden X-Leaf-CSRF-Token field, so Leaf's convenience XSRF-TOKEN
| cookie isn't needed; it's set for the whole site (path /), where installed
| in a subdirectory it would overwrite the main site's own XSRF-TOKEN (Laravel
| and others use that name).
|
*/

return [
    'cookie' => false,
];
