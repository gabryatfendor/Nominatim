"""
Tests for command line interface wrapper.
"""
import psycopg2
import pytest

import nominatim.cli

def call_nominatim(*args):
    return nominatim.cli.nominatim(module_dir='build/module',
                                   osm2pgsql_path='build/osm2pgsql/osm2pgsql',
                                   phplib_dir='lib',
                                   data_dir='.',
                                   phpcgi_path='/usr/bin/php-cgi',
                                   cli_args=args)

class MockParamCapture:
    """ Mock that records the parameters with which a function was called
        as well as the number of calls.
    """
    def __init__(self):
        self.called = 0
        self.return_value = 0

    def __call__(self, *args, **kwargs):
        self.called += 1
        self.last_args = args
        self.last_kwargs = kwargs
        return self.return_value

@pytest.fixture
def mock_run_legacy(monkeypatch):
    mock = MockParamCapture()
    monkeypatch.setattr(nominatim.cli, 'run_legacy_script', mock)
    return mock

@pytest.fixture
def mock_run_api(monkeypatch):
    mock = MockParamCapture()
    monkeypatch.setattr(nominatim.cli, 'run_api_script', mock)
    return mock


def test_cli_help(capsys):
    """ Running nominatim tool without arguments prints help.
    """
    assert 1 == call_nominatim()

    captured = capsys.readouterr()
    assert captured.out.startswith('usage:')


@pytest.mark.parametrize("command,script", [
                         (('import', '--continue', 'load-data'), 'setup'),
                         (('freeze',), 'setup'),
                         (('special-phrases',), 'specialphrases'),
                         (('replication',), 'update'),
                         (('add-data', '--tiger-data', 'tiger'), 'setup'),
                         (('add-data', '--file', 'foo.osm'), 'update'),
                         (('check-database',), 'check_import_finished'),
                         (('warm',), 'warm'),
                         (('export',), 'export')
                         ])
def test_legacy_commands_simple(mock_run_legacy, command, script):
    assert 0 == call_nominatim(*command)

    assert mock_run_legacy.called == 1
    assert mock_run_legacy.last_args[0] == script + '.php'


@pytest.mark.parametrize("name,oid", [('file', 'foo.osm'), ('diff', 'foo.osc'),
                                      ('node', 12), ('way', 8), ('relation', 32)])
def test_add_data_command(mock_run_legacy, name, oid):
    assert 0 == call_nominatim('add-data', '--' + name, str(oid))

    assert mock_run_legacy.called == 1
    assert mock_run_legacy.last_args == ('update.php', '--import-' + name, oid)


@pytest.mark.parametrize("params,do_bnds,do_ranks", [
                          ([], 1, 1),
                          (['--boundaries-only'], 1, 0),
                          (['--no-boundaries'], 0, 1),
                          (['--boundaries-only', '--no-boundaries'], 0, 0)])
def test_index_command(monkeypatch, temp_db, params, do_bnds, do_ranks):
    with psycopg2.connect(database=temp_db) as conn:
        with conn.cursor() as cur:
            cur.execute("CREATE TABLE import_status (indexed bool)")
    bnd_mock = MockParamCapture()
    monkeypatch.setattr(nominatim.cli.Indexer, 'index_boundaries', bnd_mock)
    rank_mock = MockParamCapture()
    monkeypatch.setattr(nominatim.cli.Indexer, 'index_by_rank', rank_mock)

    assert 0 == call_nominatim('index', *params)

    assert bnd_mock.called == do_bnds
    assert rank_mock.called == do_ranks


@pytest.mark.parametrize("command,params", [
                         ('postcodes', ('update.php', '--calculate-postcodes')),
                         ('word-counts', ('update.php', '--recompute-word-counts')),
                         ('address-levels', ('update.php', '--update-address-levels')),
                         ('functions', ('setup.php',)),
                         ('wiki-data', ('setup.php', '--import-wikipedia-articles')),
                         ('importance', ('update.php', '--recompute-importance')),
                         ('website', ('setup.php', '--setup-website')),
                         ])
def test_refresh_command(mock_run_legacy, command, params):
    assert 0 == call_nominatim('refresh', '--' + command)

    assert mock_run_legacy.called == 1
    assert len(mock_run_legacy.last_args) >= len(params)
    assert mock_run_legacy.last_args[:len(params)] == params


def test_refresh_importance_computed_after_wiki_import(mock_run_legacy):
    assert 0 == call_nominatim('refresh', '--importance', '--wiki-data')

    assert mock_run_legacy.called == 2
    assert mock_run_legacy.last_args == ('update.php', '--recompute-importance')


@pytest.mark.parametrize("params", [
                         ('search', '--query', 'new'),
                         ('reverse', '--lat', '0', '--lon', '0'),
                         ('lookup', '--id', 'N1'),
                         ('details', '--node', '1'),
                         ('details', '--way', '1'),
                         ('details', '--relation', '1'),
                         ('details', '--place_id', '10001'),
                         ('status',)
                         ])
def test_api_commands_simple(mock_run_api, params):
    assert 0 == call_nominatim(*params)

    assert mock_run_api.called == 1
    assert mock_run_api.last_args[0] == params[0]
