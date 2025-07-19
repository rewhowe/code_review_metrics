# Code Review Metrics

Generate code metrics via BitBucket API for gamification.

Ironically, does not support GitHub. :)

## Make Metrics

```bash
ruby metrics.rb

ruby metrics.rb --date=YYYY-MM-DD

ruby metrics.rb --debug
```

Creates a file metrics\_YYYY-MM-DD.json where YYYY-MM-DD is the current date.

The file contains metrics for activities during the week of the target date (default: Monday of the current week). The target period includes pull requests from the preceeding Monday.

ex 1. if today is 2024-01-12 (Friday), then the metrics are recorded for activities starting from 2024-01-08 (Monday). The target period includes pull requests starting from 2024-01-01 (Monday).

ex 2. if today is 2024-01-12 (Friday), a pull request created with one comment made on 2024-01-05 (Friday) and one comment made on 2024-01-08 (Monday) will be recorded as having 2 comments. However, only the second comment will be recorded in this week's activity metrics.

You can specify the target date for activities with the parameter `--date=YYYY-MM-DD`. Usually, this should be a Monday. Note that the metrics will include all activity until the current date.

ex 3. if today is 2024-01-15 (Monday), the data from ex 1 (above) can be calculated by specifying `--date=2024-01-08`. The recorded data will include activities from the 13th, 14th, and 15th.

You can use the `--debug` option to get more information about the API calls being made.

### Config

The config for data generation is in config.yaml.

```yaml
base_url: https://example.com
project: project_name
repos:
  - repo_name1
  - repo_name2
# example token: echo hoge | md5 | base64
token: YzU5NTQ4YzNjNTc2MjI4NDg2YTFmMDAzN2ViMTZhMWIK
```

* `base_url` - the base URL of your BitBucket host
* `project` - the project name of your codebase
* `repos` - a list of repository names under your project, these will be the targets of metrics calculation
* `token` - the API access token for your BitBucket user

## Render Metrics

```
metrics.php
```

Metrics are rendered as static pages.

The filepath should be a directory containing the output of `metrics.rb`.

### Config

The config for data rendering is in config.ini.

```ini
[config]
filepath_format = /home/appuser/storage/code_review_metrics/metrics_%s.json
avatar_image_url_format = https://git.example.com/users/%s/avatar.png
public_image_directory = /public/img/
```

* `filepath_format` - filepath to the generated metrics files including format string for date or wildcard
* `avatar_image_url_format` - URL for fetching user avatars; generally this should be the same as the BitBucket host
* `public_image_directory` - user avatars will be downloaded and served from here
