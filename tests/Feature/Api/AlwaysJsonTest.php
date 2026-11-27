<?php

/**
 * Everything under /api answers in JSON, whatever the client asks for. A browser opening an API
 * URL sends `Accept: text/html`, and Laravel then answered an unauthenticated request with a
 * redirect to a `login` route this API does not have — a 500 instead of a 401.
 */
it('answers an unauthenticated browser request with a JSON 401, not a redirect', function () {
    $this->get('/api/videos', ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

it('answers an unknown API path in JSON for a browser too', function () {
    $this->get('/api/no-such-endpoint', ['Accept' => 'text/html'])
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');
});
