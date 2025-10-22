[![Latest Stable Version][latest stable version]][1]
[![GitHub stars][github stars]][1] [![Total Downloads][total downloads]][1]
[![GitHub Workflow Status][github workflow status]][2]
[![Type Coverage][type coverage]][4] [![License][license]][1]
[![Donate!][donate github]][5]

# Path Hasher

## Description

A library to serialize a filesystem object (file, directory, symlink).

The current implementation focuses on the NAR (Nix ARchive) format used by Nix
for deterministic path hashing.

## Installation

`composer require loophp/path-hasher`

## Usage

### Basic Example

```php
<?php

use Loophp\PathHasher\NAR;

$path = '/path/to/your/file_or_directory';

$hash = (new NAR())->hash($path);

echo $hash; // Outputs the SRI hash (e.g., sha256-<base64>)
```

The equivalent CLI command is:

```bash
nix hash path /path/to/your/file_or_directory
```

The two outputs will match.

Methods available are:

- `hash`: Compute the
  [SRI hash](https://developer.mozilla.org/en-US/docs/Web/Security/Subresource_Integrity)
  of a given path.
- `write`: Write the NAR archive to a file or to `STDOUT`.
- `extract`: Extract a NAR archive to a specified directory.
- `stream`: Get a stream generator of the NAR archive.
- `computeHashes`: Compute hashes of the NAR archive with different algorithms.

## Code quality, tests, benchmarks

Every time changes are introduced into the library, [Github][2] runs the tests.

The library has tests written with [PHPUnit][35]. Feel free to check them out in
the `tests` directory.

Before each commit, some inspections are executed with [GrumPHP][36]; run
`composer grumphp` to check manually.

## Contributing

Feel free to contribute by sending pull requests. We are a usually very
responsive team and we will help you going through your pull request from the
beginning to the end.

For some reasons, if you can't contribute to the code and willing to help,
sponsoring is a good, sound and safe way to show us some gratitude for the hours
we invested in this package.

Sponsor me on [Github][5] and/or any of [the contributors][6].

## Changelog

See [CHANGELOG.md][43] for a changelog based on [git commits][44].

For more detailed changelogs, please check [the release changelogs][45].

[1]: https://packagist.org/packages/loophp/path-hasher
[2]: https://github.com/loophp/path-hasher/actions
[4]: https://shepherd.dev/github/loophp/path-hasher
[5]: https://github.com/sponsors/drupol
[6]: https://github.com/loophp/path-hasher/graphs/contributors
[latest stable version]:
  https://img.shields.io/packagist/v/loophp/path-hasher.svg?style=flat-square
[github stars]:
  https://img.shields.io/github/stars/loophp/path-hasher.svg?style=flat-square
[total downloads]:
  https://img.shields.io/packagist/dt/loophp/path-hasher.svg?style=flat-square
[github workflow status]:
  https://img.shields.io/github/actions/workflow/status/loophp/path-hasher/tests.yml?branch=main&style=flat-square
[type coverage]:
  https://img.shields.io/badge/dynamic/json?style=flat-square&color=color&label=Type%20coverage&query=message&url=https%3A%2F%2Fshepherd.dev%2Fgithub%2Floophp%2Fpath-hasher%2Fcoverage
[license]:
  https://img.shields.io/packagist/l/loophp/path-hasher.svg?style=flat-square
[donate github]:
  https://img.shields.io/badge/Sponsor-Github-brightgreen.svg?style=flat-square
[34]: https://github.com/loophp/path-hasher/issues
[35]: https://www.phpunit.de/
[36]: https://github.com/phpro/grumphp
[38]: https://github.com/phpstan/phpstan
[39]: https://github.com/vimeo/psalm
[43]: https://github.com/loophp/path-hasher/blob/main/CHANGELOG.md
[44]: https://github.com/loophp/path-hasher/commits/main
[45]: https://github.com/loophp/path-hasher/releases
