<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Modules\Auth\Http\Controllers\ForgotPasswordController;
use Juwri\Modules\Auth\Http\Controllers\LoginController;
use Juwri\Modules\Auth\Http\Controllers\RegisterController;
use Juwri\Modules\Auth\Http\Controllers\ResetPasswordController;
use Juwri\Modules\Auth\Http\Controllers\SocialLoginController;
use Juwri\Modules\Auth\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

\Juwri\Core\Support\ModuleRoutes::slot('Auth/auth-1');

\Juwri\Core\Support\ModuleRoutes::slot('Customers/auth-1');

\Juwri\Core\Support\ModuleRoutes::slot('Auth/auth-2');
