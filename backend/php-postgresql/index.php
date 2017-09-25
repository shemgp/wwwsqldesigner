<?php

// ---------------------------------------------------------------------
// Postgresql server backend for wwwsqldesigner
// version 0.1 beta
// Based on the mysql server backend provided with wwwsqldesigner 2.3.2
//
//
//
// Issues relating to using wwwsqldesigner with postgresl:
//  * Request dialog for a database name is not needed. Enter anything when
//    requested.
//  * There can be user-defined types in Postgresql which is not found in
//    '../../db/postgresql/datatypes.xml'.
//  * There is no auto increment column in Postgresql. Ignore the checkbox,
//    use the serial type when building and importing your tables;
// ---------------------------------------------------------------------

function get_config($name, $section = null, $else = null)
{
    if ($section !== null) {
        $ini_array = parse_ini_file('database.ini', true);
        if (isset($ini_array[$section][$name])) {
            return $ini_array[$section][$name];
        }

        return $else;
    } else {
        $ini_array = parse_ini_file('database_config.ini');
        if (isset($ini_array[$name])) {
            return $ini_array[$name];
        }
    }

    return $else;
}

// Parameters for the application database
function setup_saveloadlist()
{
    $section = 'loadlist';
    Define('HOST_ADDR', get_config('HOST_ADDR', $section, 'localhost'));            // if the database cluster is on the same server as this application use 'localhost' otherwise the appropriate address (192.168.0.2 for example).
    Define('PORT_NO', get_config('PORT_NO', $section, '5432'));                    // default port is 5432. If you have or had more than one db cluster at the same time, consider ports 5433,... etc.
    Define('DATABASE_NAME', get_config('DATABASE_NAME', $section, 'wwwsqldesigner'));    // leave as is
    Define('USER_NAME', get_config('USER_NAME', $section, 'wwwsqldesigner'));        // leave as is
    Define('PASSWORD', get_config('PASSWORD', $section, 'xxx'));                    // leave as is
    Define('TABLE', get_config('TABLE', $section, 'wwwsqldesigner'));            // leave as is
}

// Parameters for the database you want to import in the application
function setup_import()
{
    $section = 'import';
    Define('HOST_ADDR', get_config('HOST_ADDR', $section, 'localhost'));    // if the database cluster is on the same server as this application use 'localhost' otherwise the appropriate address (192.168.0.2 for example).
    Define('PORT_NO', get_config('PORT_NO', $section, '5432'));            // default port is 5432. If you have or had more than one db cluster at the same time, consider ports 5433,... etc.
    Define('DATABASE_NAME', get_config('DATABASE_NAME', $section, 'somedatabase'));    // the database you want to import
    Define('USER_NAME', get_config('USER_NAME', $section, 'someuser'));    // role having rights to read the database
    Define('PASSWORD', get_config('PASSWORD', $section, 'xxx'));        // password for role
}

function setup_temp_db()
{
    $section = 'temp';
    Define('TEMP_HOST_ADDR', get_config('HOST_ADDR', $section, 'localhost'));
    Define('TEMP_PORT_NO', get_config('PORT_NO', $section, '5432'));
    Define('TEMP_DATABASE_NAME', get_config('DATABASE_NAME', $section, 'tempdb'));
    Define('TEMP_USER_NAME', get_config('USER_NAME', $section, 'tempdb'));
    Define('TEMP_PASSWORD', get_config('PASSWORD', $section, 'xxx'));
}

function connect()
{
    $str = 'host='.HOST_ADDR.' port='.PORT_NO.' dbname='.DATABASE_NAME.' user='.USER_NAME.' password='.PASSWORD;
    $conn = pg_connect($str);
    if (!$conn) {
        header('HTTP/1.0 503 Service Unavailable');

        return;
    }

    return $conn;
}

function import($conn)
{
    //	$db = (isset($_GET["database"]) ? $_GET["database"] : "information_schema");
//	$db = pg_escape_string($conn, $db);
    $xml = '';
    $arr = array();
    @$datatypes = file('../../db/postgresql/datatypes.xml');
    $arr[] = $datatypes[0];
    $arr[] = '<sql db="postgresql">';
    for ($i = 1;$i < count($datatypes);++$i) {
        $arr[] = $datatypes[$i];
    }

    // in Postgresql comments are not stored in the ANSI information_schema (compliant to the standard);
    // so we will need to access the pg_catalog and may as well get the table names at the same time.
    $qstr = "
			SELECT 	relname as table_name,
					c.oid as table_oid,
					(SELECT pg_catalog.obj_description(c.oid, 'pg_class')) as comment
			FROM pg_catalog.pg_class c
			WHERE c.relname !~ '^(pg_|sql_)' AND relkind = 'r'
			ORDER BY table_name;
	;";

    $result = pg_query($conn, $qstr);
    while ($row = pg_fetch_array($result)) {
        $table = $row['table_name'];
        $table_oid = $row['table_oid'];
        $xml .= '<table name="'.$table.'">'."\n";
        $comment = (isset($row['comment']) ? $row['comment'] : '');
        if ($comment) {
            $xml .= '  <comment>'.$comment.'</comment>'."\n";
        }
        $qstr = '
			SELECT *, col_description('.$table_oid.",ordinal_position) as column_comment
			FROM information_schema.columns
			WHERE table_name = '".$table."'
			ORDER BY ordinal_position
		;";
        $result2 = pg_query($conn, $qstr);
        while ($row = pg_fetch_array($result2)) {
            $name = $row['column_name'];
            $type = $row['data_type'];        // maybe use "udt_name" instead to consider user types
            $comment = (isset($row['column_comment']) ? $row['column_comment'] : '');
            $null = ($row['is_nullable'] == 'YES' ? '1' : '0');
            $def = $row['column_default'];
            if (stripos($def, 'nextval') !== false) {
                $type = 'SERIAL';
                $def = '';
            }
            // $ai:autoincrement... Not in postgresql, Ignore
            $ai = '0';
            if ($def == 'NULL') {
                $def = '';
            }
            $xml .= '  <row name="'.$name.'" null="'.$null.'" autoincrement="'.$ai.'">'."\n";
            $xml .= '    <datatype>'.strtoupper($type).'</datatype>'."\n";
            $xml .= '    <default>'.$def.'</default>'."\n";
            if ($comment) {
                $xml .= '    <comment>'.$comment.'</comment>'."\n";
            }

            /* fk constraints */
            $qstr = "
				SELECT 	kku.column_name,
						ccu.table_name AS references_table,
						ccu.column_name AS references_field,
                        				tc.constraint_name
				FROM information_schema.table_constraints tc
				LEFT JOIN information_schema.constraint_column_usage ccu
					ON tc.constraint_name = ccu.constraint_name
				LEFT JOIN information_schema.key_column_usage kku
					ON kku.constraint_name = ccu.constraint_name
				WHERE constraint_type = 'FOREIGN KEY'
                				AND kku.table_name = '".$table."'
					AND kku.column_name = '".$name."'
			;";

            $result3 = pg_query($conn, $qstr);

            while ($row = pg_fetch_array($result3)) {
                $xml .= '    <relation table="'.$row['references_table'].'" name="'.$row['constraint_name'].'" row="'.$row['references_field'].'" />'."\n";
            }

            $xml .= '  </row>'."\n";
        }

        // keys
        $qstr = "
			SELECT	tc.constraint_name,
					tc.constraint_type,
					kcu.column_name
			FROM information_schema.table_constraints tc
			LEFT JOIN information_schema.key_column_usage kcu
				ON tc.constraint_catalog = kcu.constraint_catalog
				AND tc.constraint_schema = kcu.constraint_schema
				AND tc.constraint_name = kcu.constraint_name
			WHERE tc.table_name = '".$table."' AND constraint_type != 'FOREIGN KEY'
			ORDER BY tc.constraint_name
		;";
        $result2 = pg_query($conn, $qstr);
        $keyname1 = '';
        while ($row2 = pg_fetch_array($result2)) {
            $keyname = $row2['constraint_name'];
            if ($keyname != $keyname1) {
                if ($keyname1 != '') {
                    $xml .= '</key>'."\n";
                }
                if ($row2['constraint_type'] == 'PRIMARY KEY') {
                    $row2['constraint_type'] = 'PRIMARY';
                }
                if (endsWith($keyname, '_not_null') and $row2['constraint_type'] === 'CHECK') {
                    $keyname = '';
                    continue;
                }
                $xml .= '<key name="'.$keyname.'" type="'.$row2['constraint_type'].'">'."\n";
                $xml .= isset($row2['column_name']) ? '<part>'.$row2['column_name'].'</part>'."\n" : '';
            } else {
                $xml .= isset($row2['column_name']) ? '<part>'.$row2['column_name'].'</part>'."\n" : '';
            }
            $keyname1 = $keyname;
        }
        if ($keyname1 != '') {
            $xml .= '</key>'."\n";
        }

        // index
        $qstr = 'SELECT pcx."relname" as "INDEX_NAME", pa."attname" as
			"COLUMN_NAME", * FROM "pg_index" pi LEFT JOIN "pg_class" pcx ON pi."indexrelid"  =
			pcx."oid" LEFT JOIN "pg_class" pci ON pi."indrelid" = pci."oid" LEFT JOIN
			"pg_attribute" pa ON pa."attrelid" = pci."oid" AND pa."attnum" = ANY(pi."indkey")
			WHERE pci."relname" = \''.$table.'\' order by pa."attnum"';
        $result2 = pg_query($conn, $qstr);
        $idx = array();
        while ($row2 = pg_fetch_array($result2)) {
            $name = $row2['INDEX_NAME'];
            if (array_key_exists($name, $idx)) {
                $obj = $idx[$name];
            } else {
                $t = 'INDEX';
                if ($row2['indisunique'] == 't') {
                    $t = 'UNIQUE';
                    break;
                }
                if ($row2['indisprimary'] == 't') {
                    $t = 'PRIMARY';
                    break;
                }

                $obj = array(
                    'columns' => array(),
                    'type' => $t,
                );
            }

            $obj['columns'][] = $row2['COLUMN_NAME'];
            $idx[$name] = $obj;
        }

        foreach ($idx as $name => $obj) {
            $xmlkey = '<key name="'.$name.'" type="'.$obj['type'].'">'."\n";
            for ($i = 0;$i < count($obj['columns']);++$i) {
                $col = $obj['columns'][$i];
                $xmlkey .= '<part>'.$col.'</part>'."\n";
            }
            $xmlkey .= '</key>'."\n";
            $xml .= $xmlkey;
        }

        $xml .= '</table>'."\n";
    }
    $arr[] = $xml;
    $arr[] = '</sql>';

    return implode("\n", $arr);
}

function endsWith($haystack, $needle)
{
    return (substr($haystack, -strlen($needle)) === $needle);
}

$a = (isset($_GET['action']) ? $_GET['action'] : false);
switch ($a) {
    case 'list':
        setup_saveloadlist();
        $conn = connect();
        $qstr = 'SELECT keyword FROM '.TABLE.' ORDER BY dt DESC';
        $result = pg_query($conn, $qstr);
        while ($row = pg_fetch_assoc($result)) {
            echo $row['keyword']."\n";
        }
    break;
    case 'save':
        setup_saveloadlist();
        $conn = connect();
        $keyword = (isset($_GET['keyword']) ? $_GET['keyword'] : '');
        $keyword = pg_escape_string($conn, $keyword);
        $data = file_get_contents('php://input');
        if (get_magic_quotes_gpc() || get_magic_quotes_runtime()) {
            $data = stripslashes($data);
        }
        $data = pg_escape_string($conn, $data);
        $qstr = 'SELECT * FROM '.TABLE." WHERE keyword = '".$keyword."'";
        $r = pg_query($conn, $qstr);
        if (pg_num_rows($r) > 0) {
            $qstr = 'UPDATE '.TABLE." SET xmldata = '".$data."' WHERE keyword = '".$keyword."'";
            $res = pg_query($conn, $qstr);
        } else {
            $qstr = 'INSERT INTO '.TABLE." (keyword, xmldata) VALUES ('".$keyword."', '".$data."')";
            $res = pg_query($conn, $qstr);
        }
        if (!$res) {
            header('HTTP/1.0 500 Internal Server Error');
        } else {
            header('HTTP/1.0 201 Created');
        }
    break;
    case 'load':
        setup_saveloadlist();
        $conn = connect();
        $keyword = (isset($_GET['keyword']) ? $_GET['keyword'] : '');
        $keyword = pg_escape_string($conn, $keyword);
        $qstr = 'SELECT xmldata FROM '.TABLE." WHERE keyword = '".$keyword."'";
        $result = pg_query($conn, $qstr);
        $row = pg_fetch_assoc($result);
        if (!$row) {
            header('HTTP/1.0 404 Not Found');
        } else {
            header('Content-type: text/xml');
            echo $row['xmldata'];
        }
    break;
    case 'import':
        setup_import();
        $conn = connect();
        header('Content-type: text/xml');
        echo import($conn);
    break;
    case 'diff':
        get_diff($a);
    break;
    case 'applydiff':
        setup_import();
        $post_data = trim(file_get_contents('php://input'));
        $xml_data = simplexml_load_string($post_data);
        $data = urldecode($xml_data);
        if ($data == '') {
            echo 'No data';
            die();
        }

        $diff_sql = tempnam('/tmp/', 'sqlwwwdesigner-diff-');
        unlink($diff_sql);
        $diff_sql = $diff_sql.'.sql';
        $f = fopen($diff_sql, 'a');
        fwrite($f, $data);
        fclose($f);

        $psql = 'PGPASSWORD='.PASSWORD.' psql --set ON_ERROR_STOP=1 --echo-errors -h '.HOST_ADDR.
            ' -U '.USER_NAME.' '.DATABASE_NAME.' < '.$diff_sql . ' 2>&1';
        exec($psql, $output, $return_var);
        if ($return_var == 0) {
            echo 'ok';
        } else {
            echo join("\n", $output);
        }
        unlink($diff_sql);
    break;
    case 'laravelmigration':
        $migration = get_diff($a);
        if (is_in_laravel_public())
        {
            $filename = save_to_laravel_migration($migration, $_GET['class']);
            echo 'Saved Migration to '.$filename.'.';
        }
        else
        {
            echo $migration;
        }
    break;
    default: header('HTTP/1.0 501 Not Implemented');
}

function get_diff($action)
{
    setup_import();
    setup_temp_db();
    $post_data = trim(file_get_contents('php://input'));
    $xml_data = simplexml_load_string($post_data);
    $data = urldecode($xml_data);
    if ($data == '') {
        echo 'No data';
        die();
    }

    $new_dump = tempnam('/tmp/', 'sqlwwwdesigner-new-');
    unlink($new_dump);
    $new_dump = $new_dump.'.sql';
    $f = fopen($new_dump, 'a');
    fwrite($f, $data);
    fclose($f);
    //copy($new_dump, $new_dump.".fresh");

    $old_dump = tempnam('/tmp/', 'sqlwwwdesigner-old-');
    unlink($old_dump);
    $old_dump = $old_dump.'.sql';

    # clean database
    $output = '';
    $pg = 'echo "DROP OWNED BY '.TEMP_USER_NAME.'" | PGPASSWORD='.TEMP_PASSWORD.' psql -h '.TEMP_HOST_ADDR.
        ' -U '.TEMP_USER_NAME.' -d '.TEMP_DATABASE_NAME.' 2> /tmp/hehe.log; if [ $? != 0 ]; then cat /tmp/hehe.log; rm /tmp/hehe.log; exit 1; else rm /tmp/hehe.log; fi;';
    exec($pg, $output, $return_var);
    if ($return_var != 0) {
        echo $pg."\n";
        echo(implode("\n", $output));
        die();
    }

    # put new database to clean database
    $output = '';
    $psql = 'PGPASSWORD='.TEMP_PASSWORD.' psql --set ON_ERROR_STOP=1 --echo-errors -h '.TEMP_HOST_ADDR.
        ' -U '.TEMP_USER_NAME.' '.TEMP_DATABASE_NAME.' < '.$new_dump.' 2> /tmp/hehe.log; if [ $? != 0 ]; then cat /tmp/hehe.log; rm /tmp/hehe.log; exit 1; else rm /tmp/hehe.log; fi;';
    exec($psql, $output, $return_var);
    if ($return_var != 0 || stripos(join('\n', $output), 'ERROR:') !== FALSE) {
        echo $psql."\n";
        echo(implode("\n", $output));
        die();
    }

    # dump new database
    $output = '';
    $pg_dump = 'PGPASSWORD='.TEMP_PASSWORD.' pg_dump --schema-only --column-insert --no-owner -h '.TEMP_HOST_ADDR.
        ' -U '.TEMP_USER_NAME.' '.TEMP_DATABASE_NAME.' > '.$new_dump.' 2> /tmp/hehe.log; if [ $? != 0 ]; then cat /tmp/hehe.log; rm /tmp/hehe.log; exit 1; else rm /tmp/hehe.log; fi;';
    exec($pg_dump, $output, $return_var);
    if ($return_var != 0) {
        echo $pg_dump."\n";
        echo(implode("\n", $output));
        die();
    }

    # get old database
    $output = '';
    $pg_dump = 'PGPASSWORD='.PASSWORD.' pg_dump --schema-only --column-insert --no-owner -h '.HOST_ADDR.
        ' -U '.USER_NAME.' '.DATABASE_NAME.' > '.$old_dump.' 2> /tmp/hehe.log; if [ $? != 0 ]; then cat /tmp/hehe.log; rm /tmp/hehe.log; exit 1; else rm /tmp/hehe.log; fi;';
    exec($pg_dump, $output, $return_var);
    if ($return_var != 0) {
        echo $pg_dump."\n";
        echo(implode("\n", $output));
        die();
    }

    if ($action == 'laravelmigration')
    {
        # get migration
        $class = 'UnknownMigrationName';
        if (isset($_GET['class']))
            $class = $_GET['class'];

        $up = shell_exec('cd /tmp; apgdiff '.basename(trim($old_dump)).' '.basename($new_dump). ' 2>&1');
        $down = shell_exec('cd /tmp; apgdiff '.basename($new_dump).' '.basename(trim($old_dump)). ' 2>&1');
        $return = generate_laravel_migration($class, $up, $down);
    }
    else
    {
        # get diff
        $down = isset($_GET['down']) ? (bool) $_GET['down'] : false;
        if (!$down)
            $diff = 'cd /tmp; apgdiff '.basename(trim($old_dump)).' '.basename($new_dump). ' 2>&1';
        else
            $diff = 'cd /tmp; apgdiff '.basename($new_dump).' '.basename(trim($old_dump)). ' 2>&1';
        passthru($diff);
        $return = '';
    }
    # clean database
    $pg = 'echo "DROP OWNED BY '.TEMP_USER_NAME.'" | PGPASSWORD='.TEMP_PASSWORD.' psql -h '.TEMP_HOST_ADDR.
        ' -U '.TEMP_USER_NAME.' -d '.TEMP_DATABASE_NAME.' 2>&1';

    exec($pg, $output, $return_var);
    if ($return_var != 0) {
        echo $pg_restore."\n";
        echo(implode("\n", $output));
        die();
    }

    unlink('/tmp/'.basename($new_dump));
    unlink('/tmp/'.basename($old_dump));

    return $return;
}

function generate_laravel_migration($class, $up, $down)
{
    return <<<MIGRATE
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class $class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        try
        {
            DB::transaction(function() {
                DB::unprepared(<<<EOS
$up
EOS
                );
            });
            DB::commit();
        }
        catch (Exception \$e)
        {
            DB::rollBack();
            throw new Exception(\$e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        try
        {
            DB::transaction(function() {
                DB::unprepared(<<<EOS
$down
EOS
                );
            });
            DB::commit();
        }
        catch (Exception \$e)
        {
            DB::rollBack();
            throw new Exception(\$e->getMessage());
        }
    }
}
MIGRATE;
}

function is_in_laravel_public()
{
    return file_exists('../../../index.php');
}

/*
    list: 501/200
    load: 501/200/404
    save: 501/201
    import: 501/200
*/;
