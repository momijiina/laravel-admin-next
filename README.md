# Laravel Admin Next

## 日本語: OpenAI Dots による定期自動更新（計画）

Laravel Admin Next は、[z-song/laravel-admin](https://github.com/z-song/laravel-admin) をもとに、メンテナーが OpenAI Dots を活用して保守・改善する独立したプロジェクトです。

OpenAI Dots による定期的な自動更新支援を計画しています。変更点や互換性への影響の調査、必要な修正、テスト、Pull Request の作成までを支援し、レビューとマージの判断はメンテナーが行います。定期実行のスケジュールは未設定で、自動マージや自動デプロイは行いません。

最新の PHP / Laravel への対応を目指し、既存 API や他の Laravel アプリケーションとの互換性・連携を重視します。対応状況は段階的に検証するため、すべてのバージョンやアプリケーションでの動作を保証するものではありません。

**画像処理の破壊的変更:** 画像変換・サムネイルは任意依存の Intervention Image `^3.11.9` に移行しました。v2 の全 API との互換性はありません。型付きコールバックや既存設定を [画像処理の移行ガイド](IMAGE_MIGRATION.md) で確認してください。画像処理を行わない通常のアップロードには不要です。PHP の最低要件は 8.2 です。

2026-10-04 更新（PR #65 まで）: Number の整数精度・境界値と readonly / disabled、DateMultiple のネイティブ JSON オプション、ウィジェットのコールバック対応付け、Currency の設定済み小数点記号に応じた保存前変換を修正しました。Number は公開済みアセットとキャッシュの更新が必要です。DateMultiple では以前無視されていた書式・制約が有効になるため、保存値を確認してください。Currency の検証は変換前に行われ、浮動小数点の精度制限も変わりません。以前のビュー修正を含む [変更点・移行時の注意事項と検証範囲](COMPATIBILITY.md#current-maintenance-summary) を確認してください。

- [ロードマップ](ROADMAP.md)
- [互換性方針](COMPATIBILITY.md)
- [最近の変更点・アップグレード時の注意事項と検証範囲（PR #65 まで）](COMPATIBILITY.md#current-maintenance-summary)
- [元プロジェクトについて](UPSTREAM.md)

このプロジェクトは OpenAI の公式プロジェクトではなく、OpenAI による承認・推奨を示すものではありません。

## English: Periodic automated updates with OpenAI Dots (planned)

Laravel Admin Next is an independent project based on [z-song/laravel-admin](https://github.com/z-song/laravel-admin), maintained and improved by its maintainer with assistance from OpenAI Dots.

We plan to use OpenAI Dots for periodic automated maintenance assistance: investigating changes and compatibility impact, making necessary fixes, running tests, and preparing pull requests. The maintainer reviews changes and decides whether to merge them. No recurring schedule is configured yet, and changes are not automatically merged or deployed.

The project aims to support modern PHP and Laravel while prioritizing existing APIs, compatibility, and integration with other Laravel applications. Compatibility is verified incrementally; support for every version or application is not guaranteed.

**Breaking image-processing change:** transformations and thumbnails now use optional Intervention Image `^3.11.9`, with a bounded legacy API rather than full v2 compatibility. Review callbacks and settings in the [image migration guide](IMAGE_MIGRATION.md). Ordinary uploads without processing do not need it. The PHP floor remains 8.2.

Updated 2026-10-04 (through PR #65): recent fixes cover Number integer precision/bounds and readonly/disabled states, DateMultiple native JSON options, widget callback mapping, and Currency configured radix points. Refresh published Number assets and caches. Review stored dates before previously ignored DateMultiple formats/restrictions take effect. Currency validation still precedes preparation, and float precision limits remain. See [changes, upgrade cautions and verification limits](COMPATIBILITY.md#current-maintenance-summary), including earlier view fixes.

- [Roadmap](ROADMAP.md)
- [Compatibility policy](COMPATIBILITY.md)
- [Recent changes, upgrade cautions and verification limits (through PR #65)](COMPATIBILITY.md#current-maintenance-summary)
- [Upstream attribution](UPSTREAM.md)

This is not an official OpenAI project and does not imply OpenAI endorsement.

## Laravel Admin Next requirements

- PHP `^8.2` (8.2–8.x); PHP 8.3+ is recommended. PHP 7.x, 8.0 and 8.1 are no longer supported by this fork.
- The Laravel Composer constraint remains `>=5.5` for downstream resolution; it does not guarantee compatibility with every admitted release. See the [tested combinations and limits](COMPATIBILITY.md).
- Fileinfo PHP extension

The source for this fork is [momijiina/laravel-admin-next](https://github.com/momijiina/laravel-admin-next). The historical `composer require encore/laravel-admin` command below resolves the upstream package by default; it does not select this fork. This README does not announce a separate Packagist release for Laravel Admin Next.

## Original laravel-admin README / 元プロジェクトの README

The original README is preserved below for reference. Its installation instructions, requirements, badges, and links describe the upstream project.
以下は参考のために残している元プロジェクトの README です。インストール手順、動作要件、バッジ、リンクは元プロジェクトの内容です。

<p align="center">
<a href="https://laravel-admin.org/">
<img src="https://laravel-admin.org/images/logo002.png" alt="laravel-admin">
</a>

<p align="center">⛵<code>laravel-admin</code> is administrative interface builder for laravel which can help you build CRUD backends just with few lines of code.</p>

<p align="center">
<a href="https://laravel-admin.org/docs">Documentation</a> |
<a href="https://laravel-admin.org/docs/zh">中文文档</a> |
<a href="https://demo.laravel-admin.org">Demo</a> |
<a href="https://github.com/z-song/demo.laravel-admin.org">Demo source code</a> |
<a href="#extensions">Extensions</a>
</p>

<p align="center">
    <a href="https://travis-ci.org/z-song/laravel-admin">
        <img src="https://travis-ci.org/z-song/laravel-admin.svg?branch=master" alt="Build Status">
    </a>
    <a href="https://styleci.io/repos/48796179">
        <img src="https://styleci.io/repos/48796179/shield" alt="StyleCI">
    </a>
    <a href="https://packagist.org/packages/encore/laravel-admin">
        <img src="https://img.shields.io/packagist/l/encore/laravel-admin.svg?maxAge=2592000&&style=flat-square" alt="Packagist">
    </a>
    <a href="https://packagist.org/packages/encore/laravel-admin">
        <img src="https://img.shields.io/packagist/dt/encore/laravel-admin.svg?style=flat-square" alt="Total Downloads">
    </a>
    <a href="https://github.com/z-song/laravel-admin">
        <img src="https://img.shields.io/badge/Awesome-Laravel-brightgreen.svg?style=flat-square" alt="Awesome Laravel">
    </a>
    <a href="#backers" alt="sponsors on Open Collective">
        <img src="https://opencollective.com/laravel-admin/backers/badge.svg?style=flat-square" />
    </a> 
    <a href="https://www.paypal.me/zousong" alt="Paypal donate">
        <img src="https://img.shields.io/badge/Donate-Paypal-green.svg?style=flat-square" />
    </a> 
</div>

<p align="center">
    Inspired by <a href="https://github.com/sleeping-owl/admin" target="_blank">SleepingOwlAdmin</a> and <a href="https://github.com/zofe/rapyd-laravel" target="_blank">rapyd-laravel</a>.
</p>

Sponsor
------------

<a href="https://ter.li/32ifxj">
<img src="https://user-images.githubusercontent.com/1479100/102449272-dc356880-406e-11eb-9079-169c8c2af81c.png" alt="laravel-admin" width="200px;">
</a>


Requirements
------------
 - PHP >= 7.0.0
 - Laravel >= 5.5.0
 - Fileinfo PHP Extension

Installation
------------

> This package requires PHP 7+ and Laravel 5.5, for old versions please refer to [1.4](https://laravel-admin.org/docs/v1.4/#/)

First, install laravel 5.5, and make sure that the database connection settings are correct.

```
composer require encore/laravel-admin
```

Then run these commands to publish assets and config：

```
php artisan vendor:publish --provider="Encore\Admin\AdminServiceProvider"
```
After run command you can find config file in `config/admin.php`, in this file you can change the install directory,db connection or table names.

At last run following command to finish install.
```
php artisan admin:install
```

Open `http://localhost/admin/` in browser,use username `admin` and password `admin` to login.

Configurations
------------
The file `config/admin.php` contains an array of configurations, you can find the default configurations in there.

Right to left support
------------
just go to this path `<YOUR_PROJECT_PATH>\vendor\encore\laravel-admin\src\Traits\HasAssets.php` and modify `$baseCss` array for loading right to left (rtl) version of bootstap and AdminLTE css files.    
**bootstrap.min.css** change it to **bootstrap.rtl.min.css**    
**AdminLTE.min.css** change it to **AdminLTE.rtl.min.css**  

## Extensions

| Extension                                        | Description                              | laravel-admin                              |
| ------------------------------------------------ | ---------------------------------------- |---------------------------------------- |
| [helpers](https://github.com/laravel-admin-extensions/helpers)             | Several tools to help you in development | ~1.5 |
| [media-manager](https://github.com/laravel-admin-extensions/media-manager) | Provides a web interface to manage local files          | ~1.5 |
| [api-tester](https://github.com/laravel-admin-extensions/api-tester) | Help you to test the local laravel APIs          |~1.5 |
| [scheduling](https://github.com/laravel-admin-extensions/scheduling) | Scheduling task manager for laravel-admin          |~1.5 |
| [redis-manager](https://github.com/laravel-admin-extensions/redis-manager) | Redis manager for laravel-admin          |~1.5 |
| [backup](https://github.com/laravel-admin-extensions/backup) | An admin interface for managing backups          |~1.5 |
| [log-viewer](https://github.com/laravel-admin-extensions/log-viewer) | Log viewer for laravel           |~1.5 |
| [config](https://github.com/laravel-admin-extensions/config) | Config manager for laravel-admin          |~1.5 |
| [reporter](https://github.com/laravel-admin-extensions/reporter) | Provides a developer-friendly web interface to view the exception          |~1.5 |
| [wangEditor](https://github.com/laravel-admin-extensions/wangEditor) | A rich text editor based on [wangeditor](http://www.wangeditor.com/)         |~1.6 |
| [summernote](https://github.com/laravel-admin-extensions/summernote) | A rich text editor based on [summernote](https://summernote.org/)          |~1.6 |
| [china-distpicker](https://github.com/laravel-admin-extensions/china-distpicker) | 一个基于[distpicker](https://github.com/fengyuanchen/distpicker)的中国省市区选择器          |~1.6 |
| [simplemde](https://github.com/laravel-admin-extensions/simplemde) | A markdown editor based on [simplemde](https://github.com/sparksuite/simplemde-markdown-editor)          |~1.6 |
| [phpinfo](https://github.com/laravel-admin-extensions/phpinfo) | Integrate the `phpinfo` page into laravel-admin          |~1.6 |
| [php-editor](https://github.com/laravel-admin-extensions/php-editor) <br/> [python-editor](https://github.com/laravel-admin-extensions/python-editor) <br/> [js-editor](https://github.com/laravel-admin-extensions/js-editor)<br/> [css-editor](https://github.com/laravel-admin-extensions/css-editor)<br/> [clike-editor](https://github.com/laravel-admin-extensions/clike-editor)| Several programing language editor extensions based on code-mirror          |~1.6 |
| [star-rating](https://github.com/laravel-admin-extensions/star-rating) | Star Rating extension for laravel-admin          |~1.6 |
| [json-editor](https://github.com/laravel-admin-extensions/json-editor) | JSON Editor for Laravel-admin          |~1.6 |
| [grid-lightbox](https://github.com/laravel-admin-extensions/grid-lightbox) | Turn your grid into a lightbox & gallery          |~1.6 |
| [daterangepicker](https://github.com/laravel-admin-extensions/daterangepicker) | Integrates daterangepicker into laravel-admin          |~1.6 |
| [material-ui](https://github.com/laravel-admin-extensions/material-ui) | Material-UI extension for laravel-admin          |~1.6 |
| [sparkline](https://github.com/laravel-admin-extensions/sparkline) | Integrates jQuery sparkline into laravel-admin          |~1.6 |
| [chartjs](https://github.com/laravel-admin-extensions/chartjs) | Use Chartjs in laravel-admin          |~1.6 |
| [echarts](https://github.com/laravel-admin-extensions/echarts) | Use Echarts in laravel-admin          |~1.6 |
| [simditor](https://github.com/laravel-admin-extensions/simditor) | Integrates simditor full-rich editor into laravel-admin          |~1.6 |
| [cropper](https://github.com/laravel-admin-extensions/cropper) | A simple jQuery image cropping plugin.          |~1.6 |
| [composer-viewer](https://github.com/laravel-admin-extensions/composer-viewer) | A web interface of composer packages in laravel.          |~1.6 |
| [data-table](https://github.com/laravel-admin-extensions/data-table) | Advanced table widget for laravel-admin |~1.6 |
| [watermark](https://github.com/laravel-admin-extensions/watermark) | Text watermark for laravel-admin |~1.6 |
| [google-authenticator](https://github.com/ylic/laravel-admin-google-authenticator) | Google authenticator |~1.6 |



## Contributors
 This project exists thanks to all the people who contribute. [[Contribute](CONTRIBUTING.md)].
<a href="graphs/contributors"><img src="https://opencollective.com/laravel-admin/contributors.svg?width=890&button=false" /></a>
 ## Backers
 Thank you to all our backers! 🙏 [[Become a backer](https://opencollective.com/laravel-admin#backer)]
 <a href="https://opencollective.com/laravel-admin#backers" target="_blank"><img src="https://opencollective.com/laravel-admin/backers.svg?width=890"></a>
 ## Sponsors
 Support this project by becoming a sponsor. Your logo will show up here with a link to your website. [[Become a sponsor](https://opencollective.com/laravel-admin#sponsor)]
 <a href="https://opencollective.com/laravel-admin/sponsor/0/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/0/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/1/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/1/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/2/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/2/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/3/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/3/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/4/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/4/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/5/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/5/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/6/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/6/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/7/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/7/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/8/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/8/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/9/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/9/avatar.svg"></a>

Other
------------
`laravel-admin` based on following plugins or services:

+ [Laravel](https://laravel.com/)
+ [AdminLTE](https://adminlte.io/)
+ [Datetimepicker](http://eonasdan.github.io/bootstrap-datetimepicker/)
+ [font-awesome](http://fontawesome.io)
+ [moment](http://momentjs.com/)
+ [Google map](https://www.google.com/maps)
+ [Tencent map](http://lbs.qq.com/)
+ [bootstrap-fileinput](https://github.com/kartik-v/bootstrap-fileinput)
+ [jquery-pjax](https://github.com/defunkt/jquery-pjax)
+ [Nestable](http://dbushell.github.io/Nestable/)
+ [toastr](http://codeseven.github.io/toastr/)
+ [X-editable](http://github.com/vitalets/x-editable)
+ [bootstrap-number-input](https://github.com/wpic/bootstrap-number-input)
+ [fontawesome-iconpicker](https://github.com/itsjavi/fontawesome-iconpicker)
+ [sweetalert2](https://github.com/sweetalert2/sweetalert2)

License
------------
`laravel-admin` is licensed under [The MIT License (MIT)](LICENSE).
