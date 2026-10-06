# Modern Eloquent Attribute dispatch / Eloquent Attribute の判定

## English

### Contract

Ordinary scalar fields declared by a native Eloquent method returning
`Illuminate\Database\Eloquent\Casts\Attribute` now take the attribute path in:

- `$grid->column('name', 'Name')`
- `$grid->name('Name')`
- `$show->name('Name')`

Previously, these entry points only recognized legacy `getNameAttribute()`
getters before trying a same-named model method as a relation. A protected
`name(): Attribute` method could consequently be invoked through Eloquent's
method forwarding and fail instead of rendering the page. A public Attribute
method could also be invoked unnecessarily during relation detection.

The existing attribute helper first checks `hasGetMutator()`, then checks the
model's native `hasAttributeMutator()` only when `method_exists()` reports that
capability. A recognized attribute is added as an ordinary column or field;
relation dispatch is not attempted for it. This includes native protected and
public Attribute methods, getter-only, getter/setter and setter-only methods. A
setter-only method is still an attribute declaration even though it supplies no
getter. Its read value continues to follow Eloquent's normal behavior.

### Compatibility and upgrade notes

- Legacy getter detection remains first and short-circuits modern detection when
  it matches. Attribute detection delegates to Eloquent rather than adding a new
  reflection or naming convention in the package.
- Existing field/column names, default and explicit labels, and native value
  semantics remain unchanged. The fix does not call setters to render values,
  rewrite storage, or invent a display value for an absent attribute. Grid uses
  the model's native `toArray()` serialization; Show uses native `getAttribute()`
  reads. Those values can differ when legacy and modern declarations coexist or
  because of the model's `snakeAttributes` setting. This fix preserves that
  distinction rather than imposing a new value-precedence rule.
- Grid macros keep their existing dispatch precedence. Real single relations
  retain their existing relation path; this is not a nested-relation change.
- Computed Grid values still need normal Eloquent serialization configuration,
  such as the appropriate `$appends` entries for computed attributes. Recognizing
  a column does not append it automatically or override model visibility rules.
- Review custom Grid/Show subclasses or overrides of the attribute/relation
  dispatch helpers. Implementations that bypass the built-in helpers need their
  own compatible detection. Verify application-specific casts, relations and
  serialization individually.
- No PHP/Laravel minimum, Composer dependency or lockfile change is required.
  There are no asset/view changes or republishing steps, and no schema or data
  migration is needed.

For frameworks without `hasAttributeMutator()`, the capability guard keeps the
old legacy-getter/relation/fallback dispatch. It does not add the modern
Attribute API to those frameworks or establish runtime support for every version
allowed by the package's Composer constraints. Compatibility and integration
with other Laravel applications remain goals that require verification for each
application and framework combination.

### Verification and limits

`ModernAttributeDispatchTest.php` uses real Laravel's in-process HTTP kernel and
SQLite-backed Eloquent models to exercise the shipped Grid and Show views. It
compares Grid output with native Eloquent `toArray()` values and Show output
with native `getAttribute()` reads, and checks that rendering leaves the stored
row unchanged. Cases include protected/public getters, getter/setter and
setter-only declarations, snake/camel names, appended computed attributes,
null and zero. It checks that dispatch itself does not invoke the
Attribute declaration as a relation.

Compatibility controls cover legacy getter detection and its short-circuit,
existing read/serialization behavior with coexisting declarations, camel-case
serialization settings, ordinary scalar fields, default/explicit names and
labels, Grid macros, a real single-level `BelongsTo` relation and unrelated
public model methods. Explicit Show `field()` rendering is also checked as an
unchanged control.

A complementary receiver double that lacks `hasAttributeMutator()` exercises
the guarded helper fallback. That double is not a historical Laravel runtime,
does not replace Eloquent in the HTTP/SQLite scenarios, and cannot prove that
older Laravel releases boot or pass this suite.

Run from the integration consumer after following [its setup instructions](README.md):

```sh
composer test -- --filter ModernAttributeDispatchTest
```

These checks do not establish browser end-to-end behavior, non-SQLite database
support, nested or collection relation behavior, arbitrary custom casts or
application overrides, or support for every admitted PHP/Laravel version.
Historical totals elsewhere in this repository remain evidence only for their
stated revisions; they are not results for this regression.

## 日本語

### 変更内容

Eloquent 標準の `Illuminate\Database\Eloquent\Casts\Attribute` を返すメソッドで
宣言された通常のスカラー属性を、次の呼び出しで属性として判定します。

- `$grid->column('name', 'Name')`
- `$grid->name('Name')`
- `$show->name('Name')`

従来は `getNameAttribute()` 形式の getter のみを先に判定し、その後で同名の
モデルメソッドをリレーションとして呼び出していました。そのため protected の
`name(): Attribute` が Eloquent のメソッド転送に渡り、ページの描画に失敗する
場合がありました。public の Attribute メソッドもリレーション判定で不要に
呼び出される場合がありました。

既存の属性判定ヘルパーで、まず `hasGetMutator()` を確認します。続いて
`method_exists()` で API の存在を確認した場合だけ、モデル標準の
`hasAttributeMutator()` を使用します。属性なら通常の列・フィールドとして追加し、
リレーションとして呼び出しません。protected/public、getter のみ、getter と setter
の両方、setter のみのメソッドを対象にします。setter のみの属性も属性宣言として
認識しますが、読み取り値は引き続き Eloquent の通常の動作に従います。

### 互換性と更新時の注意

- 従来の getter の判定を先に行い、該当すれば新形式の判定は行いません。属性の認識は
  Eloquent に委ね、パッケージ独自のリフレクション処理や命名規則は追加しません。
- 列・フィールド名、既定・明示ラベルと値の取得方法を維持します。表示のために setter
  を呼び出したり、保存値を書き換えたり、存在しない属性の表示値を補ったりしません。
  Grid はモデル標準の `toArray()` によるシリアライズ値、Show は標準の
  `getAttribute()` による読み取り値を使います。新旧の宣言が共存する場合やモデルの
  `snakeAttributes` 設定によって、両者の値は異なることがあります。この違いを維持し、
  値の優先順位に新しい規則を追加しません。
- Grid マクロの優先順位と実際の単一リレーションの既存処理を維持します。
  入れ子のリレーションを変更する修正ではありません。
- Grid の計算属性には、必要な `$appends` など通常の Eloquent のシリアライズ設定が
  引き続き必要です。列を認識しても自動追加せず、モデルの表示制限も変更しません。
- 独自の Grid/Show 派生クラスや属性・リレーション振り分けヘルパーを確認してください。
  標準のヘルパーを使わない実装には個別の対応が必要です。アプリ固有の cast、
  リレーションとシリアライズも個別に検証してください。
- PHP/Laravel の最低要件、Composer 依存関係と lockfile は変更不要です。
  アセット・ビューの変更や再公開、スキーマ・データ移行も不要です。

`hasAttributeMutator()` がないフレームワークでは、従来の getter、リレーション、
フォールバックの分岐を維持します。古いフレームワークに Attribute API を追加する
ものではなく、Composer の許容するすべてのバージョンを保証するものでもありません。
他の Laravel アプリとの互換性・連携は、アプリとフレームワークの組み合わせごとに
検証してください。

### 検証と限界

`ModernAttributeDispatchTest.php` は実際の Laravel のプロセス内 HTTP カーネルと
SQLite の Eloquent モデルを使い、同梱の Grid/Show ビューの描画を確認します。
Grid の表示値を Eloquent 標準の `toArray()` の値、Show の表示値を標準の
`getAttribute()` の読み取り値と比較し、描画の前後で保存行が変わらないことを確認します。
protected/public の getter、getter と setter の両方、setter のみ、
snake/camel 形式の名前、appends の計算属性、null と zero を対象にします。
振り分け処理自体が Attribute 宣言をリレーションとして呼び出さないことも確認します。

互換性の対照項目は、従来の getter 判定による後続判定の省略、新旧宣言が共存する場合の
既存の読み取り・シリアライズ動作、camel 形式のシリアライズ設定、通常のスカラー属性、
名前と既定・明示ラベル、Grid マクロ、実際の単一階層の `BelongsTo` リレーション、
無関係な public モデルメソッドです。Show の明示的な `field()` の描画も既存動作の
対照として確認します。

補助的に `hasAttributeMutator()` を持たない代役のオブジェクトを使い、ガードされた
ヘルパーのフォールバックを確認します。この代役は HTTP/SQLite の Eloquent を
置き換えず、旧 Laravel での起動・テスト実行を実証するものではありません。

[実行準備](README.md) の後、integration consumer で上記のコマンドを実行してください。
ブラウザー全体の動作、SQLite 以外の DB、入れ子・複数件のリレーション、任意の cast
や独自実装、すべての PHP/Laravel バージョンのサポートは検証範囲外です。
他の文書に残る過去の実行件数は、その時点のリビジョンの証拠であり、本修正の結果では
ありません。
