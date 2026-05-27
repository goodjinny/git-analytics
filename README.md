# Git Analytics

Standalone CLI-інструмент для збору, обробки та звітності по git-комітах репозиторію.
Збирає commit-історію, виявляє відкати (reverts), агрегує статистику по розробниках і генерує markdown-звіти.

> **Standalone** — не залежить від хост-проекту та не використовує Composer. Лише чистий PHP 8.2+ і SQLite.

---

## Що робить інструмент

1. **Імпорт** з локального git-репозиторію (`git log`) у SQLite БД:
   - комміти з повними метаданими (хеш, дата, автор, повідомлення, кількість змінених рядків)
   - розробники (унікалізуються за `(author_name, author_email)`)
   - тікети (з префіксом RFC), знайдені у заголовку/тілі комітів
   - відкати — комміти виду `Revert "Merge branch 'X' ..."` з визначенням постраждалого розробника
2. **Нормалізація аліасів розробників**:
   - manual mapping (з [config/aliases.json](config/aliases.json) — gitignored; шаблон у [config/aliases.example.json](config/aliases.example.json))
   - auto-discovery через еквівалентні домени (`some-domain.com` === `some-domain.ua`)
3. **Звіти у markdown** у `reports/<project_name>/dd.mm.YYYY-dd.mm.YYYY/<key>.md` із вбудованими **Mermaid**-діаграмами, що рендеряться нативно в GitHub/GitLab. За замовчуванням генеруються звіти `commits-*` і `lines-*`; звіти `reverts-*` ввімкнено окремим прапором `--with-reverts-report` або явним `--report=reverts-*`.
4. **Інтерактивний HTML-дашборд** (Chart.js) у `reports/<period>/diagrams/index.html` — генерується за прапором `--make-charts`.

---

## Архітектура

```diagram
╭──────────────────╮      ╭────────────────────╮      ╭──────────────────────╮
│  Git repository  │─────▶│  bin/import.php    │─────▶│  data/git_analytics  │
│  (local clone)   │      │  CommitCollector   │      │       .db (SQLite)   │
╰──────────────────╯      │  RevertDetector    │      ╰──────────┬───────────╯
                          ╰────────────────────╯                 │
                                                                 │
                          ╭────────────────────╮                 │
                          │ bin/apply-         │◀────────────────┤
                          │ aliases.php        │                 │
                          │ AliasApplier       │                 │
                          ╰────────────────────╯                 │
                                                                 │
                          ╭────────────────────╮                 │
                          │  bin/report.php    │◀────────────────╯
                          │  ReportRunner      │
                          │  ReportRepository  │
                          │  MarkdownTable…    │─────╮
                          ╰────────────────────╯     │
                                                     ▼
                                              ╭──────────────╮
                                              │  reports/    │
                                              │  *.md files  │
                                              ╰──────────────╯
```

**Шари даних:**

```diagram
┌─────────────────────────────────────────────────────────────┐
│ Tables:       import_runs · developers · commits · tickets  │
│               commit_tickets · reverts                      │
├─────────────────────────────────────────────────────────────┤
│ Views:        vw_commit_facts (commit + canonical author)   │
│               vw_revert_facts (revert + canonical affected) │
│               vw_developer_canonical                        │
└─────────────────────────────────────────────────────────────┘
```

Views розв'язують `developers.alias_id` → завжди повертають **канонічного** розробника. Усі звіти використовують виключно views, тому дублікати імен автоматично зливаються.

---

## Структура проекту

```
path-to-project/git-analytics/
├── bin/
│   ├── import.php          # Збір з git → БД
│   ├── apply-aliases.php   # Застосувати alias mapping
│   ├── report.php          # Генерація markdown-звітів
│   └── export.php          # Експорт у CSV / XLSX
├── src/
│   ├── Config.php          # Завантаження config/config.php
│   ├── Db.php              # PDO singleton + schema init
│   ├── Logger.php          # Логування в output/import.log
│   ├── RequirementsChecker.php
│   ├── GitCommandRunner.php
│   ├── CommitCollector.php
│   ├── TicketExtractor.php
│   ├── RevertDetector.php
│   ├── ImportPipeline.php
│   ├── DeveloperRepository.php
│   ├── CommitRepository.php
│   ├── TicketRepository.php
│   ├── CommitTicketRepository.php
│   ├── RevertRepository.php
│   ├── ImportRunRepository.php
│   ├── AliasApplier.php           # Manual + auto-discovery alias logic
│   ├── ReportDefinitions.php      # Реєстр звітів
│   ├── ReportRepository.php       # SQL для звітів (через views)
│   ├── MarkdownTableBuilder.php   # Simple + pivot рендерери таблиць
│   ├── MermaidChartBuilder.php    # Mermaid-блоки в markdown
│   ├── HtmlDashboardBuilder.php   # Chart.js HTML-дашборд
│   ├── ReportRunner.php           # Оркестратор генерації звітів
│   ├── ReportTableBuilder.php     # Будує 2D-таблиці для експорту
│   ├── CsvExporter.php            # CSV-вивід (UTF-8 + BOM)
│   ├── XlsxExporter.php           # XLSX через ZipArchive (без Composer)
│   └── ExportRunner.php           # Оркестратор експорту CSV/XLSX
├── data/
│   └── git_analytics.db    # SQLite (створюється автоматично)
├── output/
│   ├── import.log          # Логи виконання
│   ├── commits/*.json      # Снапшоти імпорту
│   └── reverts/*.json
├── reports/
│   └── project_name/                   # basename(git.repo_path) або reports.project_subdir
│       └── dd.mm.YYYY-dd.mm.YYYY/
│           ├── commits-full-period.md      # таблиця + Mermaid діаграма
│           ├── commits-by-year.md          # таблиця + Mermaid (bar + line)
│           ├── commits-by-month.md
│           ├── lines-full-period.md
│           ├── lines-by-year.md
│           ├── lines-by-month.md
│           ├── reverts-full-period.md
│           ├── reverts-by-year.md
│           ├── reverts-by-month.md
│           ├── full-report.md              # комбінований звіт (commits-* + lines-*; з --with-reverts-report також reverts-*)
│           └── diagrams/                   # створюється з --make-charts
│               └── index.html              # інтерактивний Chart.js дашборд
├── config/
│   ├── config.php           # DB path, repo path, output path (gitignored)
│   ├── config.example.php   # Шаблон конфігу (комітимо)
│   ├── aliases.json         # Реальні alias-пари + еквівалентні домени (gitignored)
│   └── aliases.example.json # Шаблон з нейтральними прикладами (комітимо)
├── schema.sqlite.sql       # DDL для SQLite (taблиці + views)
├── schema.sql              # MySQL варіант (не використовується)
├── task.md                 # Технічна постановка задачі
├── make-plan.md            # План реалізації imports/reverts
└── make-reports-plan.md    # План реалізації report.php
```

---

## Вимоги

- **PHP** ≥ 8.2 з розширеннями: `pdo`, `pdo_sqlite`, `json`, `mbstring`, `zip` (тільки для XLSX-експорту)
- **git** у `$PATH`
- Доступ до локального git-репозиторію (за замовч. — той самий, що містить `path-to-project/git-analytics/`)

Перевірити середовище:

```bash
php path-to-project/git-analytics/bin/import.php --check-requirements
```

---

## Конфігурація

Один файл — [config/config.php](config/config.php) (скопіюйте з [config/config.example.php](config/config.example.php) — реальний `config/config.php` у `.gitignore`):

```php
return [
    'db' => ['path' => dirname(__DIR__) . '/data/git_analytics.db'],

    // Project map (required).
    // Key   = project name (--project=<name>, subfolder in reports/)
    // Value = absolute path to git repository
    // Default (--project omitted): first entry in the map.
    'git-projects' => [
        'awesome-project' => '/path/to/awesome-project',
        'some-mvp'        => '/path/to/mvp-project',
    ],

    'output' => ['path' => dirname(__DIR__) . '/output'],
];
```

Шлях до git-репозиторію можна переозначити через `--repo-path=…` у `import.php`.

> **Іменування теки звітів.** Ключ з масиву `git-projects` використовується як назва підтеки: `reports/<project-name>/dd.mm.YYYY-dd.mm.YYYY/`.

---

## Швидкий старт

```bash
cd test/git-analytics

# 1. Імпорт даних (чистий старт, гілка визначається автоматично)
php bin/import.php \
    --date-from=2023-08-28 \
    --date-to=2026-04-25 \
    --fresh

# 2. Згенерувати всі звіти (аліаси застосовуються автоматично)
php bin/report.php \
    --date-from=2023-08-28 \
    --date-to=2026-04-25
```

Звіти з'являться у `reports/project_name/28.08.2023-25.04.2026/`.

---

## Робочий процес (workflow)

```diagram
╭───────────────────╮      ╭────────────────────╮      ╭───────────────────╮
│ 1. import.php     │─────▶│ 2. (опц.) apply-   │─────▶│ 3. report.php     │
│    --fresh        │      │    aliases.php     │      │    + аліаси       │
│    збирає коміти  │      │    [report.php     │      │    автоматично    │
│    з git → SQLite │      │    робить це сам]  │      │                   │
╰───────────────────╯      ╰────────────────────╯      ╰───────────────────╯
```

**Крок 2 необов'язковий** — `bin/report.php` автоматично запускає `AliasApplier` перед генерацією (можна вимкнути прапором `--skip-aliases`).

---

## CLI-скрипти

### `bin/import.php` — збір з git

Збирає історію комітів і відкати з гілки за вказаний період. Зберігає в SQLite.

| Параметр | Тип | Опис |
|----------|-----|------|
| `--branch=<name>` | optional | Гілка для аналізу. Якщо не вказано — автоматично визначається `master` або `main` |
| `--date-from=<YYYY-MM-DD>` | required | Початок періоду (включно) |
| `--date-to=<YYYY-MM-DD>` | required | Кінець періоду (включно) |
| `--project=<name>` | optional | Ключ проекту з масиву `projects` у `config.php` (default: перший запис) |
| `--repo-path=<path>` | optional | Шлях до git-репо — перевизначає `--project` і `config.php` |
| `--fresh` | flag | Видалити БД перед імпортом (повне перезаймання) |
| `--dry-run` | flag | Парсити та логувати без запису в БД |
| `--check-requirements` | flag | Перевірити вимоги і вийти |
| `--help` | | Допомога |

**Що відбувається при імпорті:**
1. Перевірка вимог → `--fresh` (опц.) → init схеми
2. `git log --pretty=...` → парсинг
3. Upsert розробників, комітів, тікетів, зв'язків
4. Детекція revert-комітів за патерном `Revert "..."` + резолюція affected developer
5. Снапшот сирих даних у `output/commits/` і `output/reverts/`

---

### `bin/apply-aliases.php` — нормалізація розробників

Об'єднує дублікатні записи розробників. Запускається **автоматично** з `report.php`; окремий запуск потрібен тільки для попереднього перегляду (`--dry-run`).

| Параметр | Опис |
|----------|------|
| `--dry-run` | Показати зміни без запису в БД |
| `--help` | Допомога |

**Логіка:**
1. Manual pairs — завантажуються з [config/aliases.json](config/aliases.json) (gitignored; шаблон — [config/aliases.example.json](config/aliases.example.json)). Пошук по `(author_name, author_email)`, не за id — стабільно після `--fresh`.
2. Auto-discovery за еквівалентними доменами з того ж конфігу:
   ```json
   {
       "equivalent_domains": [
           ["some-domain.com", "some-domain.ua"]
       ]
   }
   ```
   Перший домен у групі — preferred canonical. Якщо два записи мають однаковий local-part email (наприклад `jane.smith`) у різних доменах із цієї групи — один стає аліасом іншого.
3. Вибір канонічного запису у автопарі:
   - запис із повним ім'ям (author_name ≠ local-part) перемагає
   - на preferred domain
   - з меншим id
4. Manual pairs мають **пріоритет** — авто-пари не суперечать їм.

Після застосування:
- `developers.alias_id` встановлюється для аліасних записів
- `commits.developer_id` і `reverts.affected_developer_id` переприсвоюються на canonical
- Views перестворюються

---

### `bin/report.php` — генерація звітів

Тільки читає БД (не імпортує з git) і пише markdown-файли у `reports/`.

| Параметр | Тип | Опис |
|----------|-----|------|
| `--branch=<name>` | optional | Гілка. Якщо не вказано — автоматично визначається `master` або `main` |
| `--date-from=<YYYY-MM-DD>` | required | Початок періоду |
| `--date-to=<YYYY-MM-DD>` | required | Кінець періоду |
| `--project=<name>` | optional | Ключ проекту з масиву `projects` у `config.php` (default: перший запис) |
| `--report=<key>` | optional | Який звіт згенерувати (default: `full-report`) |
| `--alias=<value>` | optional | Фільтр **тільки для `reverts-*`** — за email/local-part/підрядком імені |
| `--detail` | flag | Дописати в `reverts-*` список окремих revert-комітів |
| `--with-reverts-report` | flag | Включити секції `reverts-*` при генерації `full-report` або `all`. За замовчуванням ці секції пропускаються.<br>Не впливає на `--report=reverts-*` — ті завжди генеруються. |
| `--make-charts` | flag | Згенерувати HTML-дашборд з Chart.js у `diagrams/` |
| `--skip-aliases` | flag | НЕ запускати `AliasApplier` перед генерацією |
| `--force` | flag | Перезаписувати існуючі `.md` без попередження |
| `--help` | | Допомога з повним списком звітів |

**Доступні звіти (`--report=<key>`):**

| Ключ | Опис |
|------|------|
| `full-report` | (default) Комбінований звіт — `commits-*` і `lines-*` секції в одному файлі. Додати `--with-reverts-report`, щоб включити також `reverts-*`. |
| `all` | Те саме, плюс кожна секція окремим файлом. Reverts-секції також лише з `--with-reverts-report`. |
| `commits-full-period` | Кількість комітів по розробникам за період |
| `commits-by-year` | Те саме за роками (pivot) |
| `commits-by-month` | Те саме за місяцями (pivot) |
| `lines-full-period` | Сума змінених рядків по розробникам |
| `lines-by-year` | За роками |
| `lines-by-month` | За місяцями |
| `reverts-full-period` | Кількість відкатів по розробникам |
| `reverts-by-year` | За роками |
| `reverts-by-month` | За місяцями |

> **Технічні коміти** (`is_merge_commit=1` OR `is_revert_commit=1` OR `technical_commit=1`) виключаються з звітів `commits-*` і `lines-*`.

**Особливості `reverts-*` звітів:**
- Всі розробники з комітами у періоді показуються у звіті: спочатку ті, в кого є відкати (сортування DESC), потім із нулями (алфавітом).
- `--alias=<value>` фільтрує лише по одному розробнику. Збіг шукається у:
  1. повному email (e.g. `jane.smith@example.com`)
  2. local-part email (e.g. `jane.smith`)
  3. підрядку імені/display (e.g. `Smith`)
- `--detail` додає секцію `## Деталі відкатів` зі списком комітів: дата, хеш, тікет, тема відкату, відкочений коміт.

---

### `bin/export.php` — експорт у CSV / XLSX

Тільки читає БД (як `report.php`) і пише структуровані табличні експорти у `reports/<period>/exports/`. Жодних залежностей від Composer — XLSX будується чистим PHP через `ZipArchive` (потрібне розширення `ext-zip`).

| Параметр | Тип | Опис |
|----------|-----|------|
| `--branch=<name>` | optional | Гілка. Якщо не вказано — автоматично визначається `master` або `main` |
| `--date-from=<YYYY-MM-DD>` | required | Початок періоду |
| `--date-to=<YYYY-MM-DD>` | required | Кінець періоду |
| `--project=<name>` | optional | Ключ проекту з масиву `projects` у `config.php` (default: перший запис) |
| `--report=<key>` | optional | Який звіт експортувати (default: `all` — усі 9) |
| `--format=<fmt>` | optional | `csv` \| `xlsx` \| `both` (default: `both`) |
| `--alias=<value>` | optional | Фільтр **тільки для `reverts-*`** (як у `report.php`) |
| `--detail` | flag | Додатково експортувати `revert-details.csv` / sheet `revert-details` |
| `--skip-aliases` | flag | НЕ запускати `AliasApplier` перед експортом |
| `--force` | flag | Перезаписувати існуючі файли без попередження |
| `--help` | | Допомога |

**Що генерується:**

```
reports/<project_name>/dd.mm.YYYY-dd.mm.YYYY/exports/
├── commits-full-period.csv      ┐
├── commits-by-year.csv          │
├── commits-by-month.csv         │
├── lines-full-period.csv        │  format ∈ {csv, both}
├── lines-by-year.csv            │
├── lines-by-month.csv           │
├── reverts-full-period.csv      │
├── reverts-by-year.csv          │
├── reverts-by-month.csv         │
├── revert-details.csv           │  лише з --detail
│                                ┘
└── git-analytics.xlsx           ← format ∈ {xlsx, both}, sheet на кожний звіт
```

**Формати:**
- **CSV** — UTF-8 із BOM (Excel правильно визначає кодування), розділювач `,`, лапки за RFC 4180. Файл містить рядок-заголовок із назвою звіту, шапку, дані та підсумковий рядок.
- **XLSX** — один воркбук з одним аркушем на звіт (ім'я аркуша = ключ звіту, обрізане до 31 символу за специфікацією Excel). Числові комірки зберігаються як числа (правильно сортуються/підсумовуються в Excel), текст — як inline strings. Жирним виділяються заголовки і рядок підсумків.

**Семантика експорту відповідає markdown-звіту:**
- Pivot-звіти (`*-by-year`, `*-by-month`) включають колонки на кожен період + колонку `Всього` + рядок підсумків по колонках.
- Для `reverts-*` зеро-розробники (мали коміти, але без відкатів) додаються після рядків з даними.
- Аліаси розробників застосовуються через views (`vw_commit_facts`, `vw_revert_facts`).

---

## Приклади

```bash
# Повний звіт за весь період (перший проект із config.php за замовч.)
php bin/report.php --branch=master --date-from=2023-08-28 --date-to=2026-04-25

# Звіт для конкретного проекту
php bin/report.php --project=awesome-project \
    --branch=master --date-from=2023-08-28 --date-to=2026-04-25

# Повний звіт із включеними reverts-*
php bin/report.php --branch=master --date-from=2023-08-28 --date-to=2026-04-25 \
    --with-reverts-report

# Окремий звіт із перезаписом
php bin/report.php --branch=master --date-from=2023-08-28 --date-to=2026-04-25 \
    --report=commits-full-period --force

# Усі commits-* і lines-* окремими файлами + комбінований
php bin/report.php --branch=master --date-from=2023-08-28 --date-to=2026-04-25 \
    --report=all --force

# Усі 9 звітів окремими файлами + комбінований (включаючи reverts-*)
php bin/report.php --branch=master --date-from=2023-08-28 --date-to=2026-04-25 \
    --report=all --with-reverts-report --force

# Звіт по відкатах — генерується напряму без --with-reverts-report
php bin/report.php --branch=master --date-from=2025-12-01 --date-to=2025-12-31 \
    --report=reverts-full-period

# Reverts конкретного розробника з деталями
php bin/report.php --branch=master --date-from=2025-12-01 --date-to=2025-12-31 \
    --report=reverts-by-month --detail --alias=jane.smith

# Reverts усіх — із деталями
php bin/report.php --branch=master --date-from=2025-12-01 --date-to=2025-12-31 \
    --report=reverts-full-period --detail

# Без auto-aliasing
php bin/report.php --branch=master --date-from=2023-08-28 --date-to=2026-04-25 \
    --skip-aliases

# Згенерувати markdown + інтерактивний HTML-дашборд
php bin/report.php --branch=master --date-from=2023-08-28 --date-to=2026-04-25 \
    --make-charts --force

# Перевірити, що зробив би apply-aliases (без запису в БД)
php bin/apply-aliases.php --dry-run

# Експорт усіх 9 звітів у CSV + XLSX (default)
php bin/export.php --branch=master --date-from=2023-08-28 --date-to=2026-04-25 --force

# Експорт для іншого проекту
php bin/export.php --project=some-mvp \
    --branch=master --date-from=2023-08-28 --date-to=2026-04-25 --force

# Лише XLSX за один звіт
php bin/export.php --branch=master --date-from=2025-12-01 --date-to=2025-12-31 \
    --report=commits-by-month --format=xlsx --force

# CSV з деталями відкатів для конкретного розробника
php bin/export.php --branch=master --date-from=2025-12-01 --date-to=2025-12-31 \
    --format=csv --detail --alias=jane.smith --force
```

---

## Графіки і діаграми

Доступні **два формати** графічного представлення звітів — обидва автоматизовані.

### 1. Mermaid — інлайн у markdown (за замовчуванням)

До кожного `*.md` файлу автоматично додається секція `## Діаграма` із Mermaid-блоком. Працює без жодних залежностей — GitHub/GitLab/Bitbucket рендерять Mermaid нативно.

**Що генерується:**

| Тип звіту        | Mermaid-блоки                                            |
|------------------|----------------------------------------------------------|
| simple (`*-full-period`) | bar chart — топ-10 розробників за метрикою       |
| pivot (`*-by-year`, `*-by-month`) | bar chart (топ-10 totals) + line chart (динаміка за періодами) |

**Приклад згенерованого блоку:**

````markdown
## Діаграма

```mermaid
xychart-beta
    title "Топ-10: Загальна кількість комітів"
    x-axis ["Sam Johnson", "jane.smith", "Іванов І.І.", ...]
    y-axis "Комітів" 0 --> 1700
    bar [1561, 1201, 1143, ...]
```
````

**Customization:** константи у [`MermaidChartBuilder`](src/MermaidChartBuilder.php):
- `TOP_N` — кількість розробників (за замовчуванням 10)
- `LABEL_MAX` — максимальна довжина імені для x-axis (default 22)

### 2. Chart.js HTML-дашборд (за `--make-charts`)

Створюється один self-contained файл `reports/<period>/diagrams/index.html` з усіма згенерованими звітами у вигляді інтерактивних графіків (zoom, tooltip, hover). Кількість секцій відповідає набору згенерованих звітів: 6 (без reverts) або 9 (з `--with-reverts-report`).

**Структура HTML:**

```diagram
╭─────────────────────────────────────────────────╮
│  Header: branch, period, alias filter, time     │
├─────────────────────────────────────────────────┤
│  Navigation (anchors to generated sections)     │
├─────────────────────────────────────────────────┤
│  Section: commits-full-period                   │
│    Horizontal bar chart, top-10                 │
├─────────────────────────────────────────────────┤
│  Section: commits-by-year                       │
│    Multi-line chart, top-10 devs × periods      │
├─────────────────────────────────────────────────┤
│  ... more sections (incl. reverts-* if          │
│      --with-reverts-report was passed) ...      │
╰─────────────────────────────────────────────────╯
```

**Типи графіків у дашборді:**
- **simple** звіти → horizontal bar chart (топ-10 розробників)
- **pivot** звіти → multi-line chart (одна лінія на розробника, до 10 ліній)

**Залежності:** лише Chart.js v4 з CDN `cdn.jsdelivr.net` (один `<script>`). Дашборд відкривається у будь-якому сучасному браузері без сервера.

**Запуск:**

```bash
php bin/report.php --branch=master --date-from=2025-12-01 --date-to=2025-12-31 \
    --make-charts --force
# → reports/<project_name>/01.12.2025-31.12.2025/diagrams/index.html  (6 графіків: commits-* + lines-*)

php bin/report.php --branch=master --date-from=2025-12-01 --date-to=2025-12-31 \
    --with-reverts-report --make-charts --force
# → reports/<project_name>/01.12.2025-31.12.2025/diagrams/index.html  (9 графіків: commits-* + lines-* + reverts-*)
```

**Customization:** [`HtmlDashboardBuilder`](src/HtmlDashboardBuilder.php):
- `TOP_N` — кількість розробників на графік
- inline CSS у тілі класу для зміни кольорів/розмірів

### Коли який формат використовувати

| Сценарій                                  | Формат                  |
|-------------------------------------------|-------------------------|
| Code review / PR-обговорення              | Mermaid (рендериться в GitHub/GitLab) |
| Презентація керівництву                   | HTML-дашборд (інтерактивний) |
| Архів для версіонування у git             | Mermaid (текст, diff-friendly) |
| Demo в браузері без репозиторію           | HTML-дашборд            |
| Експорт у Confluence/Notion               | Mermaid (підтримується багатьма платформами) |

---

## Логіка детекції відкатів

Алгоритм у [`RevertDetector`](src/RevertDetector.php):

1. Коміт із заголовком `Revert "..."` позначається `is_revert_commit = 1`.
2. З повідомлення витягуються:
   - назва відкоченої гілки (`Merge branch 'X' into 'develop'`)
   - тікет (наприклад `RFC-NNNNN`)
3. Резолюція **affected_developer** (постраждалий):
   - **`branch_author`** — за git log знайти першого автора, що створив branch
   - **`ticket_commit_author`** — автор оригінального коміту з тим самим тікетом
   - **`message_match`** — за збігом підрядку у повідомленнях
   - **`manual`** / **`unknown`** — fallback
4. Зберігається у `reverts.affected_developer_id` (після auto-aliasing — canonical id).

---

## Логіка аліасів — швидкий вступ

```diagram
╭──────────────────────────────────╮
│ developers (по email + name)     │
│                                  │
│  #17  sjohnson                   │
│       <sjohnson@example.org>     │──┐
│                                  │  │ alias_id
│  #37  Sam Johnson                │◀─┘
│       <sjohnson@example.com>     │
╰──────────────────────────────────╯
            │
            │ commit.developer_id перепризначається з alias → canonical
            ▼
╭──────────────────────────────────╮
│ vw_commit_facts                  │
│   canonical_developer_id = 37    │  ← звіти агрегують по цьому полю
│   canonical_author_display = …   │
╰──────────────────────────────────╯
```

Без застосування аліасів `sjohnson` (логін) і `Sam Johnson` (повне ім'я) рахувались би як два різні розробники у звітах.

---

## SQLite — корисні запити

```sql
-- Топ розробників за комітами (без техкомітів, з аліасами)
SELECT canonical_developer_id, canonical_author_display, COUNT(*) AS commits
FROM vw_commit_facts
WHERE target_branch = 'develop'
  AND technical_commit = 0
GROUP BY canonical_developer_id
ORDER BY commits DESC;

-- Усі відкати конкретного розробника
SELECT revert_date, revert_commit_hash_short, ticket_code, revert_commit_subject
FROM vw_revert_facts
WHERE LOWER(affected_author_email) LIKE 'jane.smith%'
ORDER BY revert_date DESC;
```

---

## Troubleshooting

| Симптом | Причина / Рішення |
|---------|-------------------|
| `SQLSTATE[23000]: FOREIGN KEY constraint failed` під час import | Запустити `bin/import.php ... --fresh` для чистого імпорту |
| `Неможливо створити звіт: у БД немає даних...` | Спочатку зробити `bin/import.php` для того ж branch + period |
| У звіті по одному розробнику кілька рядків (логін + повне ім'я) | Не застосовано аліаси. Запустити без `--skip-aliases` або `bin/apply-aliases.php` |
| `Missing analytics view: vw_commit_facts` | View не створено. Запустити `bin/apply-aliases.php` (перестворює views) або `bin/import.php` (initSchema створює) |
| `git: not found` у `--check-requirements` | Встановити git і додати у `$PATH` |
| Хочу спробувати alias-логіку без запису | `php bin/apply-aliases.php --dry-run` |
