<?php

test('the login screen is served at the root url', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});
