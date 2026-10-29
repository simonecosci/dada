<?php

it('disables public registration', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'X', 'email' => 'x@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertStatus(404);
});
