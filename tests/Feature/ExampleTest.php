<?php

test('the application returns a successful response', function () {
    $response = $this->get('/');

    // The root URL redirects to login for unauthenticated users
    $response->assertRedirect();
});
