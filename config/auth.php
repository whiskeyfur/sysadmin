<?php

/*
| This app doesn't use leafs/auth (login is unwrapping the encrypted
| user-data field; see CLAUDE.md). Leaf MVC still reads auth.session to
| decide whether to turn on CSRF protection, and errors when the key is
| missing, so it is set here. Sessions are used, so CSRF stays on.
*/
return [
    'session' => true,
];
