# Контроль уязвимостей поставки и платформы

Тип: `operations`. Статус: `current`. Сверено с кодом: 2026-10-28.

Отсутствие package installation не устраняет сторонний код: в поставке есть фиксированные PHP libraries, а HTTP выполняют PHP и Go-бинарник RoadRunner. Хеши [manifests](../../third_party/manifest.json) проверяют целостность включённых файлов, но не отсутствие известных уязвимостей. Этот документ владеет границей security checks; [автономная поставка](../design/autonomous-core.md) описывает состав архива.

## Реализованные проверки

| Контур | Источник и проверка | Ограничение |
| --- | --- | --- |
| GitHub Actions | Dependabot еженедельно предлагает обновления; actions закреплены полными commit SHA | Изменения рассматриваются вручную, automerge отсутствует |
| PostgreSQL development image | Dependabot docker-compose для корневого docker-compose.yml | Обновления версий, не CVE-аудит ОС и PostgreSQL production |
| PHP libraries | OSV querybatch по точным name/version из runtime и SQL-codegen manifests | Покрытие базы advisories; локальные patches не отменяют upstream finding автоматически |
| PHP quality tools | OSV по версиям трёх PHAR tools из tools manifest | Вложенные зависимости PHAR пока отдельно не инвентаризируются; это не полный SBOM |
| PHP HTTP worker | Проверка PHP >=8.5.12 перед сборкой приложения и worker loop | Сравнение upstream versions не подтверждает исправления ОС, расширений или vendor backports |
| Новые PHP security releases | Официальный JSON feed PHP 8.5; security release выше принятого floor завершает аудит ошибкой | Требуется обновить floor в коде, а не только свежую PHP в CI |
| RoadRunner | govulncheck -mode=binary на явно переданном executable, включая Go stdlib и записанные Go modules | Для stripped executable анализ консервативен на уровне модулей; достижимость уязвимого кода не устанавливается |

Compatibility metapackage spiral/roadrunner содержит metadata, а не сервер. Он исключён из списка исполняемых PHP libraries: его версия не идентифицирует RR executable. Реальный сервер проверяется отдельно, с печатью версии, Go build version и SHA-256. Bundled contract .proto sources также не являются исполняемыми Packagist libraries и не отправляются в этот version audit.

## Команды и сеть

```bash
php tools/project.php security:inventory
php tools/project.php security:audit
php tools/project.php security:platform --rr=/absolute/path/to/rr --report=platform.sarif
```

Inventory работает без сети. security:audit передаёт только package names/versions в api.osv.dev и читает PHP release metadata с www.php.net; исходники и credentials не отправляются. security:platform требует установленную PHP не ниже floor и RR 3.0.1+ в ветке 3.x, запускает bundled govulncheck и читает данные vuln.go.dev. Он проверяет выбранный executable, а не произвольный RR в PATH. Для deployment его нужно запускать с тем же PHP и RR, которые будут выполнять worker.

Сетевые проверки не устанавливают пакеты или инструменты. Govulncheck v1.9.0 собран из фиксированных исходников Go toolchain 1.27.3 для Linux amd64 и macOS arm64; binaries, module checksums, compiler flags и notices находятся в [tools distribution](../../third_party/tools/manifest.json). Go toolchain не нужен для запуска проверки. Два scanner binaries добавляют около 20 MiB к checkout. Эта distribution исключена из runtime archive.

Это явное исключение для получения **advisory data**, а не новый download path для dependencies. Обычные verify/verify:full, generation и build остаются без сети. CI сначала подготавливает PHP и фиксированный RR executable с проверкой release SHA-256 как внешнюю платформу, затем выполняет проверки. На HTTP path нет обращения к advisory services; PHP floor проверяется один раз при запуске каждого worker, не для каждого запроса. Ускорение этим изменением не заявляется.

Ошибка сети, невалидный/неполный ответ, OSV pagination или неразобранный finding завершают security check с ненулевым статусом. PHP HTTPS responses ограничены 4 MiB и socket timeout 30 s; дочерние security processes — 120 s и 2 MiB diagnostics. Проверка версии RR имеет отдельные пределы 10 s/64 KiB. Общий job ограничен 15 минутами. Govulncheck запускается в SARIF mode: его exit 0 не означает отсутствия findings. ScanReport проверяет полный binary/symbol report и перечисляет все results; каждый требует отсутствия уязвимости либо действующего заключения not_affected. Ошибка самого scanner всегда блокирует проверку. Необязательный --report сохраняет исходный SARIF без удаления findings, а stdout показывает итог классификации.

## CI и релиз

[Security workflow](../../.github/workflows/security.yml) выполняется ежедневно в 06:17 UTC, вручную и через reusable вызов из CI/release. CI запускает его для push/PR в master независимо от автономных quality checks. Release packaging/upload зависит от успешного security job. Online failure блокирует публикацию архива; локальная build-команда остаётся автономной и не является разрешением релиза.

Job проверяет hashes tools перед запуском, версии PHP/RR, PHP advisories и реальные HTTP checks на подготовленной платформе. Находка одного аудита не скрывает diagnostics другого; текстовые отчёты и исходный SARIF сохраняются как GitHub artifact на 14 дней. У security job только contents: read, checkout не сохраняет credentials. Scheduled runs проверяют default branch и требуют, чтобы GitHub Actions были включены; доставка notifications определяется настройками GitHub пользователя. Workflow активен после push от 2026-10-23. Настройки GitHub, branch protection и alerts этим изменением удалённо не включаются.

## Исходное состояние и реакция

На 2026-10-23 PHP2Shell связан с [GHSA-w8hw-w9fx-j79w](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) и [GHSA-6wmf-4rv5-p3rp](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp). Принятый upstream floor — [PHP 8.5.12](https://www.php.net/releases/8_5_12.php). RoadRunner не является доказательством отсутствия этих PHP-дефектов. CLI tools сохраняют технический минимум PHP 8.5 для проверки/обновления checkout; запуск HTTP на 8.5.9/8.5.11 отклоняется. Это не рекомендация оставлять старую CLI PHP установленной.

[RR 3.0.1](https://github.com/roadrunner-server/roadrunner/releases/tag/v3.0.1) обновил Go до 1.27.3 с исправлением [GO-2026-6809](https://pkg.go.dev/vuln/GO-2026-6809). Первый CI от 2026-10-23 и scheduled run от 2026-10-27 падали только на GO-2026-5932; verify:full, packaging и HTTP checks проходили. Повторный аудит 2026-10-28 добавил GO-2026-6860 и GO-2026-6866 в x/net/html. Все три исходных finding сохраняются в SARIF; их ограниченная классификация описана ниже.

## Разбор RR 3.0.1 от 2026-10-28

Проверен официальный Linux amd64 executable SHA-256 `1584e42a826596cb31fa70479bc4d6169ad658415e0db955f36bdf7239e8bf94`. В нём отсутствует symbol table, поэтому govulncheck подставляет известные уязвимые символы из всех модулей. Строка «Your code calls vulnerable functions» в таком SARIF не является доказанной call chain.

[Заключение](../../tools/security/assessments/roadrunner.json) имеет status not_affected и justification vulnerable_code_not_present для GO-2026-5932, GO-2026-6860 и GO-2026-6866. [Доказательства](../../tools/security/assessments/roadrunner-imports.json) содержат точную upstream revision ed89c2ec991f64b072297fcbee5ad7f058779199, source archive hash, build info, 1221 импортированный пакет и два совпавших списка всех 213 dependency module names/versions. Ни openpgp и его subpackages, ни x/net/html не входят в этот граф. Отдельный source audit govulncheck -scan=package подтвердил ноль затронутых импортируемых пакетов; три advisory остаются только на уровне модулей.

Это заключение на основании исходников доверенного upstream release. Оно не является byte-for-byte reproducible-build attestation: executable записывает vcs.modified=true, что явно сохранено в evidence. Применять вывод к другой сборке, платформе или всем версиям RoadRunner нельзя.

RoadRunnerReview разрешает классификацию только при одновременном совпадении SHA-256 binary, evidence и свежего полного advisory JSON из vuln.go.dev. Проверяются совпадение source/binary module inventories и отсутствие затронутых пакетов в imports. Заключение действует с 2026-10-28 по 2026-11-27 включительно в UTC; длительность свыше 31 дня отвергается кодом. Новая advisory, изменение её определения, другой artifact, повреждённые evidence и истечение срока вновь блокируют gate. Флага ignore и общего исключения для x/crypto или x/net нет. macOS binary этим заключением не покрыт.

Для повторной проверки сопровождающий отдельно готовит точные upstream sources, Go 1.27.3 и module cache. Это разовая процедура исследования/обновления evidence вне команд проекта; обычным checks Go не нужен, скачивание модулей в них не добавляется. В директории указанных upstream sources выполняются:

```bash
GOTOOLCHAIN=go1.27.3 GOWORK=off GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go list -deps ./cmd/rr
GOTOOLCHAIN=go1.27.3 GOWORK=off GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go list -deps -f '{{if .Module}}{{.Module.Path}} {{.Module.Version}}{{end}}' ./cmd/rr
GOTOOLCHAIN=go1.27.3 GOWORK=off GOOS=linux GOARCH=amd64 CGO_ENABLED=0 /path/to/govulncheck -scan=package -show=verbose ./cmd/rr
go version -m -json /path/to/released/rr
```

После ручной сверки обновляются JSON evidence/review и их hashes, затем выполняются verify и настоящий security:platform. Доказательство отсутствия пакетов не переносится на новый artifact одним изменением hash. При фактическом использовании затронутого кода нужны обновление/замена платформы или проверенная сборка без этого кода.

## Реакция на новую находку

При новом finding:

1. Сохранить advisory ID, затронутый artifact/hash и точную версию; отделить наличие модуля от доказанной достижимости. Для подтверждённого false positive подготовить ограниченное заключение с evidence и сроком; для неизвестного результата gate остаётся закрытым.
2. Обновить platform executable/image либо bundled source/tool из проверенного upstream. Для bundled files сохранить notices/provenance и обновить оба уровня hashes; package installation не возвращать.
3. Пересмотреть PHP floor при новом security release и проверить отказ старой версии, запуск исправленной и реальные RR HTTP checks. Для SQL/БД — также PostgreSQL checks.
4. Выполнить автономный verify и online audits. Развёрнутые серверы обновляет оператор: зелёный CI не исправляет уже работающий PHP/RR и не проверяет его окружение.

Следующие отдельные задачи — инвентаризация внутренних PHAR dependencies и CVE scan реального deployment image/ОС/расширений/OpenSSL/libpq/PostgreSQL. Текущий gate не заявляет их покрытие и не гарантирует отсутствие неизвестных уязвимостей.
