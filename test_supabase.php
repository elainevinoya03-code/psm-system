<?php

require 'config.php';

$res = supabase_request("users?select=*");

echo "<pre>";
print_r($res);