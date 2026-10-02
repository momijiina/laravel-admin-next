<?php

// A consumer bootstrap file, executed by the real admin.bootstrap middleware.
config(['integration.bootstrap_calls' => config('integration.bootstrap_calls', 0) + 1]);
