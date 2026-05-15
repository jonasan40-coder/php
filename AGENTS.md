# Project rules

- PHP + MySQL のWebアプリ。
- DB接続は config.php を require_once して、mysqli接続変数 $conn を使う。
- config.php はGit管理しない。
- 本番環境は ConoHa WING。
- 既存画面のUIを大きく崩さない。
- SQLはプリペアドステートメントを優先する。
- PHP初心者でも保守しやすい書き方にする。
- 変更後はPHP構文チェックを行う。

## Check commands

```bash
find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l
