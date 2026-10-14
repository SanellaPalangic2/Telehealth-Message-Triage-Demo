<?php

test('the home page redirects to the inbox', function () {
    $this->get('/')->assertRedirect('/inbox');
});
