<?php
@define('CONST_LibDir', dirname(dirname(__FILE__)));

require_once(CONST_LibDir.'/init-cmd.php');

loadSettings(getcwd());

$term_colors = array(
                'green' => "\033[92m",
                'red' => "\x1B[31m",
                'normal' => "\033[0m"
);

$print_success = function ($message = 'OK') use ($term_colors) {
    echo $term_colors['green'].$message.$term_colors['normal']."\n";
};

$print_fail = function ($message = 'Failed') use ($term_colors) {
    echo $term_colors['red'].$message.$term_colors['normal']."\n";
};

$oDB = new Nominatim\DB;


function isReverseOnlyInstallation()
{
    global $oDB;
    return !$oDB->tableExists('search_name');
}

// Check (guess) if the setup.php included --drop
function isNoUpdateInstallation()
{
    global $oDB;
    return $oDB->tableExists('placex') && !$oDB->tableExists('planet_osm_rels') ;
}


echo 'Checking database got created ... ';
if ($oDB->checkConnection()) {
    $print_success();
} else {
    $print_fail();
    echo <<< END
    Hints:
    * Is the database server started?
    * Check the NOMINATIM_DATABASE_DSN variable in your local .env
    * Try connecting to the database with the same settings

END;
    exit(1);
}


echo 'Checking nominatim.so module installed ... ';
$sStandardWord = $oDB->getOne("SELECT make_standard_name('a')");
if ($sStandardWord === 'a') {
    $print_success();
} else {
    $print_fail();
    echo <<< END
    The Postgresql extension nominatim.so was not found in the database.
    Hints:
    * Check the output of the CMmake/make installation step
    * Does nominatim.so exist?
    * Does nominatim.so exist on the database server?
    * Can nominatim.so be accessed by the database user?

END;
    exit(1);
}

if (!isNoUpdateInstallation()) {
    echo 'Checking place table ... ';
    if ($oDB->tableExists('place')) {
        $print_success();
    } else {
        $print_fail();
        echo <<< END
        * The import didn't finish.
        Hints:
        * Check the output of the utils/setup.php you ran.
        Usually the osm2pgsql step failed. Check for errors related to
        * the file you imported not containing any places
        * harddrive full
        * out of memory (RAM)
        * osm2pgsql killed by other scripts, for consuming to much memory

    END;
        exit(1);
    }
}


echo 'Checking indexing status ... ';
$iUnindexed = $oDB->getOne('SELECT count(*) FROM placex WHERE indexed_status > 0');
if ($iUnindexed == 0) {
    $print_success();
} else {
    $print_fail();
    echo <<< END
    The indexing didn't finish. There is still $iUnindexed places. See the
    question 'Can a stopped/killed import process be resumed?' in the
    troubleshooting guide.

END;
    exit(1);
}

echo "Search index creation\n";
$aExpectedIndices = array(
    // sql/indices.src.sql
    'idx_word_word_id',
    'idx_place_addressline_address_place_id',
    'idx_placex_rank_search',
    'idx_placex_rank_address',
    'idx_placex_parent_place_id',
    'idx_placex_geometry_reverse_lookuppolygon',
    'idx_placex_geometry_reverse_placenode',
    'idx_osmline_parent_place_id',
    'idx_osmline_parent_osm_id',
    'idx_postcode_id',
    'idx_postcode_postcode'
);
if (!isReverseOnlyInstallation()) {
    $aExpectedIndices = array_merge($aExpectedIndices, array(
        // sql/indices_search.src.sql
        'idx_search_name_nameaddress_vector',
        'idx_search_name_name_vector',
        'idx_search_name_centroid'
    ));
}
if (!isNoUpdateInstallation()) {
    $aExpectedIndices = array_merge($aExpectedIndices, array(
        'idx_placex_pendingsector',
        'idx_location_area_country_place_id',
        'idx_place_osm_unique',
    ));
}

foreach ($aExpectedIndices as $sExpectedIndex) {
    echo "Checking index $sExpectedIndex ... ";
    if ($oDB->indexExists($sExpectedIndex)) {
        $print_success();
    } else {
        $print_fail();
        echo <<< END
        Hints:
        * Run './utils/setup.php --create-search-indices --ignore-errors' to
          create missing indices.

END;
        exit(1);
    }
}

echo 'Checking search indices are valid ... ';
$sSQL = <<< END
    SELECT relname
    FROM pg_class, pg_index
    WHERE pg_index.indisvalid = false
      AND pg_index.indexrelid = pg_class.oid;
END;
$aInvalid = $oDB->getCol($sSQL);
if (empty($aInvalid)) {
    $print_success();
} else {
    $print_fail();
    echo <<< END
    At least one index is invalid. That can happen, e.g. when index creation was
    disrupted and later restarted. You should delete the affected indices and
    run the index stage of setup again.
    See the question 'Can a stopped/killed import process be resumed?' in the
    troubleshooting guide.
    Affected indices: 
END;
    echo join(', ', $aInvalid) . "\n";
    exit(1);
}



if (getSettingBool('USE_US_TIGER_DATA')) {
    echo 'Checking TIGER table exists ... ';
    if ($oDB->tableExists('location_property_tiger')) {
        $print_success();
    } else {
        $print_fail();
        echo <<< END
        Table 'location_property_tiger' does not exist. Run the TIGER data
        import again.

END;
        exit(1);
    }
}




exit(0);
