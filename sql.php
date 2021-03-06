<?php
/* vim: set expandtab sw=4 ts=4 sts=4: */
/**
 * SQL executor
 *
 * @todo    we must handle the case if sql.php is called directly with a query
 *          that returns 0 rows - to prevent cyclic redirects or includes
 * @package PhpMyAdmin
 */

/**
 * Gets some core libraries
 */
require_once 'libraries/common.inc.php';
require_once 'libraries/Table.class.php';
require_once 'libraries/Header.class.php';
require_once 'libraries/check_user_privileges.lib.php';
require_once 'libraries/bookmark.lib.php';
require_once 'libraries/sql.lib.php';
require_once 'libraries/sqlparser.lib.php';

$response = PMA_Response::getInstance();
$header   = $response->getHeader();
$scripts  = $header->getScripts();
$scripts->addFile('jquery/jquery-ui-timepicker-addon.js');
$scripts->addFile('tbl_change.js');
// the next one needed because sql.php may do a "goto" to tbl_structure.php
$scripts->addFile('tbl_structure.js');
$scripts->addFile('indexes.js');
$scripts->addFile('gis_data_editor.js');

/**
 * Set ajax_reload in the response if it was already set
 */
if (isset($ajax_reload) && $ajax_reload['reload'] === true) {
    $response->addJSON('ajax_reload', $ajax_reload);
}


/**
 * Defines the url to return to in case of error in a sql statement
 */
// Security checkings
if (! empty($goto)) {
    $is_gotofile     = preg_replace('@^([^?]+).*$@s', '\\1', $goto);
    if (! @file_exists('' . $is_gotofile)) {
        unset($goto);
    } else {
        $is_gotofile = ($is_gotofile == $goto);
    }
} else {
    if (empty($table)) {
        $goto = $cfg['DefaultTabDatabase'];
    } else {
        $goto = $cfg['DefaultTabTable'];
    }
    $is_gotofile  = true;
} // end if

if (! isset($err_url)) {
    $err_url = (! empty($back) ? $back : $goto)
        . '?' . PMA_generate_common_url($db)
        . ((strpos(' ' . $goto, 'db_') != 1 && strlen($table))
            ? '&amp;table=' . urlencode($table)
            : ''
        );
} // end if

// Coming from a bookmark dialog
if (isset($_POST['bkm_fields']['bkm_sql_query'])) {
    $sql_query = $_POST['bkm_fields']['bkm_sql_query'];
} elseif (isset($_GET['sql_query'])) {
    $sql_query = $_GET['sql_query'];
}

// This one is just to fill $db
if (isset($_POST['bkm_fields']['bkm_database'])) {
    $db = $_POST['bkm_fields']['bkm_database'];
}


// During grid edit, if we have a relational field, show the dropdown for it.
if (isset($_REQUEST['get_relational_values'])
    && $_REQUEST['get_relational_values'] == true
) {
    $column = $_REQUEST['column'];
    if ($_SESSION['tmp_user_values']['relational_display'] == 'D'
        && isset($display_field)
        && strlen($display_field)
        && isset($_REQUEST['relation_key_or_display_column'])
        && $_REQUEST['relation_key_or_display_column']
    ) {
        $curr_value = $_REQUEST['relation_key_or_display_column'];
    } else {
        $curr_value = $_REQUEST['curr_value'];
    }
    $dropdown = PMA_getHtmlForRelationalColumnDropdown(
        $db, $table, $column, $curr_value
    );
    $response = PMA_Response::getInstance();
    $response->addJSON('dropdown', $dropdown);
    exit;
}

// Just like above, find possible values for enum fields during grid edit.
if (isset($_REQUEST['get_enum_values']) && $_REQUEST['get_enum_values'] == true) {
    $column = $_REQUEST['column'];
    $curr_value = $_REQUEST['curr_value'];
    $dropdown = PMA_getHtmlForEnumColumnDropdown($db, $table, $column, $curr_value);
    $response = PMA_Response::getInstance();
    $response->addJSON('dropdown', $dropdown);
    exit;
}


// Find possible values for set fields during grid edit.
if (isset($_REQUEST['get_set_values']) && $_REQUEST['get_set_values'] == true) {
    $column = $_REQUEST['column'];
    $curr_value = $_REQUEST['curr_value'];
    $select = PMA_getHtmlForSetColumn($db, $table, $column, $curr_value);
    $response = PMA_Response::getInstance();
    $response->addJSON('select', $select);
    exit;
}

/**
 * Check ajax request to set the column order
 */
if (isset($_REQUEST['set_col_prefs']) && $_REQUEST['set_col_prefs'] == true) {
    $pmatable = new PMA_Table($table, $db);
    $retval = false;

    // set column order
    if (isset($_REQUEST['col_order'])) {
        $col_order = explode(',', $_REQUEST['col_order']);
        $retval = $pmatable->setUiProp(
            PMA_Table::PROP_COLUMN_ORDER,
            $col_order,
            $_REQUEST['table_create_time']
        );
        if (gettype($retval) != 'boolean') {
            $response = PMA_Response::getInstance();
            $response->isSuccess(false);
            $response->addJSON('message', $retval->getString());
            exit;
        }
    }

    // set column visibility
    if ($retval === true && isset($_REQUEST['col_visib'])) {
        $col_visib = explode(',', $_REQUEST['col_visib']);
        $retval = $pmatable->setUiProp(
            PMA_Table::PROP_COLUMN_VISIB, $col_visib,
            $_REQUEST['table_create_time']
        );
        if (gettype($retval) != 'boolean') {
            $response = PMA_Response::getInstance();
            $response->isSuccess(false);
            $response->addJSON('message', $retval->getString());
            exit;
        }
    }

    $response = PMA_Response::getInstance();
    $response->isSuccess($retval == true);
    exit;
}

// Default to browse if no query set and we have table
// (needed for browsing from DefaultTabTable)
if (empty($sql_query) && strlen($table) && strlen($db)) {
    include_once 'libraries/bookmark.lib.php';
    $book_sql_query = PMA_Bookmark_get(
        $db,
        '\'' . PMA_Util::sqlAddSlashes($table) . '\'',
        'label',
        false,
        true
    );

    if (! empty($book_sql_query)) {
        $GLOBALS['using_bookmark_message'] = PMA_message::notice(
            __('Using bookmark "%s" as default browse query.')
        );
        $GLOBALS['using_bookmark_message']->addParam($table);
        $GLOBALS['using_bookmark_message']->addMessage(
            PMA_Util::showDocu('faq', 'faq6-22')
        );
        $sql_query = $book_sql_query;
    } else {
        $sql_query = 'SELECT * FROM ' . PMA_Util::backquote($table);
    }
    unset($book_sql_query);

    // set $goto to what will be displayed if query returns 0 rows
    $goto = '';
} else {
    // Now we can check the parameters
    PMA_Util::checkParameters(array('sql_query'));
}

/**
 * Parse and analyze the query
 */
require_once 'libraries/parse_analyze.inc.php';


/**
 * Check rights in case of DROP DATABASE
 *
 * This test may be bypassed if $is_js_confirmed = 1 (already checked with js)
 * but since a malicious user may pass this variable by url/form, we don't take
 * into account this case.
 */
if (PMA_hasNoRightsToDropDatabase(
    $analyzed_sql_results, $cfg['AllowUserDropDatabase'])
) {
    PMA_Util::mysqlDie(
        __('"DROP DATABASE" statements are disabled.'),
        '',
        '',
        $err_url
    );
} // end if

// Include PMA_Index class for use in PMA_DisplayResults class
require_once './libraries/Index.class.php';

require_once 'libraries/DisplayResults.class.php';

$displayResultsObject = new PMA_DisplayResults(
    $GLOBALS['db'], $GLOBALS['table'], $GLOBALS['goto'], $GLOBALS['sql_query']
);

$displayResultsObject->setConfigParamsForDisplayTable();

/**
 * Need to find the real end of rows?
 */
if (isset($find_real_end) && $find_real_end) {
    $unlim_num_rows = PMA_Table::countRecords($db, $table, true);
    $_SESSION['tmp_user_values']['pos'] = @((ceil(
        $unlim_num_rows / $_SESSION['tmp_user_values']['max_rows']
    ) - 1) * $_SESSION['tmp_user_values']['max_rows']);
}


/**
 * Bookmark add
 */
if (isset($_POST['store_bkm'])) {
    $result = PMA_Bookmark_save(
        $_POST['bkm_fields'],
        (isset($_POST['bkm_all_users'])
            && $_POST['bkm_all_users'] == 'true' ? true : false
        )
    );
    $response = PMA_Response::getInstance();
    if ($response->isAjax()) {
        if ($result) {
            $msg = PMA_message::success(__('Bookmark %s created'));
            $msg->addParam($_POST['bkm_fields']['bkm_label']);
            $response->addJSON('message', $msg);
        } else {
            $msg = PMA_message::error(__('Bookmark not created'));
            $response->isSuccess(false);
            $response->addJSON('message', $msg);
        }
        exit;
    } else {
        // go back to sql.php to redisplay query; do not use &amp; in this case:
        PMA_sendHeaderLocation(
            $cfg['PmaAbsoluteUri'] . $goto
            . '&label=' . $_POST['bkm_fields']['bkm_label']
        );
    }
} // end if


/**
 * Sets or modifies the $goto variable if required
 */
if ($goto == 'sql.php') {
    $is_gotofile = false;
    $goto = 'sql.php?'
          . PMA_generate_common_url($db, $table)
          . '&amp;sql_query=' . urlencode($sql_query);
} // end if

/**
 * Go back to further page if table should not be dropped
 */
if (isset($_REQUEST['btnDrop']) && $_REQUEST['btnDrop'] == __('No')) {
    if (! empty($back)) {
        $goto = $back;
    }
    if ($is_gotofile) {
        if (strpos($goto, 'db_') === 0 && strlen($table)) {
            $table = '';
        }
        $active_page = $goto;
        include '' . PMA_securePath($goto);
    } else {
        PMA_sendHeaderLocation(
            $cfg['PmaAbsoluteUri'] . str_replace('&amp;', '&', $goto)
        );
    }
    exit();
} // end if


// assign default full_sql_query
$full_sql_query = $sql_query;

// Handle remembered sorting order, only for single table query
if (PMA_isRememberSortingOrder($analyzed_sql_results)) {
    PMA_handleSortOrder($db, $table, $analyzed_sql, $full_sql_query);
}

$sql_limit_to_append = '';
// Do append a "LIMIT" clause?
if (PMA_isAppendLimitClause($analyzed_sql_results)) {
    $sql_limit_to_append = ' LIMIT ' . $_SESSION['tmp_user_values']['pos']
        . ', ' . $_SESSION['tmp_user_values']['max_rows'] . " ";
    $full_sql_query = PMA_getSqlWithLimitClause(
        $full_sql_query,
        $analyzed_sql,
        $sql_limit_to_append
    );

    /**
     * @todo pretty printing of this modified query
     */
    if (isset($display_query)) {
        // if the analysis of the original query revealed that we found
        // a section_after_limit, we now have to analyze $display_query
        // to display it correctly

        if (! empty($analyzed_sql[0]['section_after_limit'])
            && trim($analyzed_sql[0]['section_after_limit']) != ';'
        ) {
            $analyzed_display_query = PMA_SQP_analyze(
                PMA_SQP_parse($display_query)
            );
            $display_query  = $analyzed_display_query[0]['section_before_limit']
                . "\n" . $sql_limit_to_append
                . $analyzed_display_query[0]['section_after_limit'];
        }
    }
}

if (strlen($db)) {    
    // Checks if the current database has changed
    // This could happen if the user sends a query like "USE `database`;"
    $current_db = $GLOBALS['dbi']->fetchValue('SELECT DATABASE()');
    if ($db !== $current_db) {
        $reload = 1;
    }
    unset($current_db);
    $GLOBALS['dbi']->selectDb($db);
}

//  E x e c u t e    t h e    q u e r y

// Only if we didn't ask to see the php code
if (isset($GLOBALS['show_as_php']) || ! empty($GLOBALS['validatequery'])) {
    unset($result);
    $num_rows = 0;
    $unlim_num_rows = 0;
} else {
    if (isset($_SESSION['profiling']) && PMA_Util::profilingSupported()) {
        $GLOBALS['dbi']->query('SET PROFILING=1;');
    }

    // Measure query time.
    $querytime_before = array_sum(explode(' ', microtime()));

    $result = @$GLOBALS['dbi']->tryQuery(
        $full_sql_query, null, PMA_DatabaseInterface::QUERY_STORE
    );

    // If a stored procedure was called, there may be more results that are
    // queued up and waiting to be flushed from the buffer. So let's do that.
    do {
        $GLOBALS['dbi']->storeResult();
        if (! $GLOBALS['dbi']->moreResults()) {
            break;
        }
    } while ($GLOBALS['dbi']->nextResult());

    $is_procedure = false;

    // Since multiple query execution is anyway handled,
    // ignore the WHERE clause of the first sql statement
    // which might contain a phrase like 'call '
    if (preg_match("/\bcall\b/i", $full_sql_query)
        && empty($analyzed_sql[0]['where_clause'])
    ) {
        $is_procedure = true;
    }

    $querytime_after = array_sum(explode(' ', microtime()));

    $GLOBALS['querytime'] = $querytime_after - $querytime_before;

    // Displays an error message if required and stop parsing the script
    $error = $GLOBALS['dbi']->getError();
    if ($error) {
        if ($is_gotofile) {
            if (strpos($goto, 'db_') === 0 && strlen($table)) {
                $table = '';
            }
            $active_page = $goto;
            $message = PMA_Message::rawError($error);

            if ($GLOBALS['is_ajax_request'] == true) {
                $response = PMA_Response::getInstance();
                $response->isSuccess(false);
                $response->addJSON('message', $message);
                exit;
            }

            /**
             * Go to target path.
             */
            include '' . PMA_securePath($goto);
        } else {
            $full_err_url = $err_url;
            if (preg_match('@^(db|tbl)_@', $err_url)) {
                $full_err_url .=  '&amp;show_query=1&amp;sql_query='
                    . urlencode($sql_query);
            }
            PMA_Util::mysqlDie($error, $full_sql_query, '', $full_err_url);
        }
        exit;
    }
    unset($error);

    // If there are no errors and bookmarklabel was given,
    // store the query as a bookmark
    if (! empty($bkm_label) && ! empty($import_text)) {
        include_once 'libraries/bookmark.lib.php';
        $bfields = array(
                     'dbase' => $db,
                     'user'  => $cfg['Bookmark']['user'],
                     'query' => urlencode($import_text),
                     'label' => $bkm_label
        );

        // Should we replace bookmark?
        if (isset($bkm_replace)) {
            $bookmarks = PMA_Bookmark_getList($db);
            foreach ($bookmarks as $key => $val) {
                if ($val == $bkm_label) {
                    PMA_Bookmark_delete($db, $key);
                }
            }
        }

        PMA_Bookmark_save($bfields, isset($_POST['bkm_all_users']));

        $bookmark_created = true;
    } // end store bookmarks

    // Gets the number of rows affected/returned
    // (This must be done immediately after the query because
    // mysql_affected_rows() reports about the last query done)

    if (! $is_affected) {
        $num_rows = ($result) ? @$GLOBALS['dbi']->numRows($result) : 0;
    } elseif (! isset($num_rows)) {
        $num_rows = @$GLOBALS['dbi']->affectedRows();
    }

    // Grabs the profiling results
    if (isset($_SESSION['profiling']) && PMA_Util::profilingSupported()) {
        $profiling_results = $GLOBALS['dbi']->fetchResult('SHOW PROFILE;');
    }

    // tmpfile remove after convert encoding appended by Y.Kawada
    if (function_exists('PMA_Kanji_fileConv')
        && (isset($textfile) && file_exists($textfile))
    ) {
        unlink($textfile);
    }

    // Counts the total number of rows for the same 'SELECT' query without the
    // 'LIMIT' clause that may have been programatically added

    $justBrowsing = false;
    if (empty($sql_limit_to_append)) {
        $unlim_num_rows         = $num_rows;
        // if we did not append a limit, set this to get a correct
        // "Showing rows..." message
        //$_SESSION['tmp_user_values']['max_rows'] = 'all';
    } elseif ($is_select) {

        //    c o u n t    q u e r y

        // If we are "just browsing", there is only one table,
        // and no WHERE clause (or just 'WHERE 1 '),
        // we do a quick count (which uses MaxExactCount) because
        // SQL_CALC_FOUND_ROWS is not quick on large InnoDB tables

        // However, do not count again if we did it previously
        // due to $find_real_end == true
        if (PMA_isJustBrowsing(
            $analyzed_sql_results,isset($find_real_end) ? $find_real_end : null)
        ) {
            // "j u s t   b r o w s i n g"
            $justBrowsing = true;
            $unlim_num_rows = PMA_Table::countRecords(
                $db, 
                $table, 
                $force_exact = true
            );

        } else { // n o t   " j u s t   b r o w s i n g "

            // add select expression after the SQL_CALC_FOUND_ROWS

            // for UNION, just adding SQL_CALC_FOUND_ROWS
            // after the first SELECT works.

            // take the left part, could be:
            // SELECT
            // (SELECT
            $count_query = PMA_SQP_format(
                $parsed_sql,
                'query_only',
                0,
                $analyzed_sql[0]['position_of_first_select'] + 1
            );
            $count_query .= ' SQL_CALC_FOUND_ROWS ';
            // add everything that was after the first SELECT
            $count_query .= PMA_SQP_format(
                $parsed_sql,
                'query_only',
                $analyzed_sql[0]['position_of_first_select'] + 1
            );
            // ensure there is no semicolon at the end of the
            // count query because we'll probably add
            // a LIMIT 1 clause after it
            $count_query = rtrim($count_query);
            $count_query = rtrim($count_query, ';');

            // if using SQL_CALC_FOUND_ROWS, add a LIMIT to avoid
            // long delays. Returned count will be complete anyway.
            // (but a LIMIT would disrupt results in an UNION)

            if (! isset($analyzed_sql[0]['queryflags']['union'])) {
                $count_query .= ' LIMIT 1';
            }

            // run the count query

            $GLOBALS['dbi']->tryQuery($count_query);
            // if (mysql_error()) {
            // void.
            // I tried the case
            // (SELECT `User`, `Host`, `Db`, `Select_priv` FROM `db`)
            // UNION (SELECT `User`, `Host`, "%" AS "Db",
            // `Select_priv`
            // FROM `user`) ORDER BY `User`, `Host`, `Db`;
            // and although the generated count_query is wrong
            // the SELECT FOUND_ROWS() work! (maybe it gets the
            // count from the latest query that worked)
            //
            // another case where the count_query is wrong:
            // SELECT COUNT(*), f1 from t1 group by f1
            // and you click to sort on count(*)
            // }
            $unlim_num_rows = $GLOBALS['dbi']->fetchValue('SELECT FOUND_ROWS()');
        } // end else "just browsing"

    } else { // not $is_select
         $unlim_num_rows         = 0;
    } // end rows total count

    // if a table or database gets dropped, check column comments.
    if (isset($purge) && $purge == '1') {
        /**
         * Cleanup relations.
         */
        include_once 'libraries/relation_cleanup.lib.php';

        if (strlen($table) && strlen($db)) {
            PMA_relationsCleanupTable($db, $table);
        } elseif (strlen($db)) {
            PMA_relationsCleanupDatabase($db);
        } else {
            // VOID. No DB/Table gets deleted.
        } // end if relation-stuff
    } // end if ($purge)

    // If a column gets dropped, do relation magic.
    if (isset($dropped_column)
        && strlen($db)
        && strlen($table)
        && ! empty($dropped_column)
    ) {
        include_once 'libraries/relation_cleanup.lib.php';
        PMA_relationsCleanupColumn($db, $table, $dropped_column);
        // to refresh the list of indexes (Ajax mode)
        $extra_data['indexes_list'] = PMA_Index::getView($table, $db);
    } // end if column was dropped
} // end else "didn't ask to see php code"

// No rows returned -> move back to the calling page
if ((0 == $num_rows && 0 == $unlim_num_rows) || $is_affected) {
    // Delete related tranformation information
    if (PMA_isDeleteTransformationInfo($analyzed_sql_results)) {
        include_once 'libraries/transformations.lib.php';
        if ($analyzed_sql[0]['querytype'] == 'ALTER') {
            if (stripos($analyzed_sql[0]['unsorted_query'], 'DROP') !== false) {
                $drop_column = PMA_getColumnNameInColumnDropSql(
                    $analyzed_sql[0]['unsorted_query']
                );

                if ($drop_column != '') {
                    PMA_clearTransformations($db, $table, $drop_column);
                }
            }

        } else if (($analyzed_sql[0]['querytype'] == 'DROP') && ($table != '')) {
            PMA_clearTransformations($db, $table);
        }
    }

    if ($is_delete) {
        $message = PMA_Message::getMessageForDeletedRows($num_rows);
    } elseif ($is_insert) {
        if ($is_replace) {
            // For replace we get DELETED + INSERTED row count,
            // so we have to call it affected
            $message = PMA_Message::getMessageForAffectedRows($num_rows);
        } else {
            $message = PMA_Message::getMessageForInsertedRows($num_rows);
        }
        $insert_id = $GLOBALS['dbi']->insertId();
        if ($insert_id != 0) {
            // insert_id is id of FIRST record inserted in one insert,
            // so if we inserted multiple rows, we had to increment this
            $message->addMessage('[br]');
            // need to use a temporary because the Message class
            // currently supports adding parameters only to the first
            // message
            $_inserted = PMA_Message::notice(__('Inserted row id: %1$d'));
            $_inserted->addParam($insert_id + $num_rows - 1);
            $message->addMessage($_inserted);
        }
    } elseif ($is_affected) {
        $message = PMA_Message::getMessageForAffectedRows($num_rows);

        // Ok, here is an explanation for the !$is_select.
        // The form generated by sql_query_form.lib.php
        // and db_sql.php has many submit buttons
        // on the same form, and some confusion arises from the
        // fact that $message_to_show is sent for every case.
        // The $message_to_show containing a success message and sent with
        // the form should not have priority over errors
    } elseif (! empty($message_to_show) && ! $is_select) {
        $message = PMA_Message::rawSuccess(htmlspecialchars($message_to_show));
    } elseif (! empty($GLOBALS['show_as_php'])) {
        $message = PMA_Message::success(__('Showing as PHP code'));
    } elseif (isset($GLOBALS['show_as_php'])) {
        /* User disable showing as PHP, query is only displayed */
        $message = PMA_Message::notice(__('Showing SQL query'));
    } elseif (! empty($GLOBALS['validatequery'])) {
        $message = PMA_Message::notice(__('Validated SQL'));
    } else {
        $message = PMA_Message::success(
            __('MySQL returned an empty result set (i.e. zero rows).')
        );
    }

    if (isset($GLOBALS['querytime'])) {
        $_querytime = PMA_Message::notice('(' . __('Query took %01.4f sec') . ')');
        $_querytime->addParam($GLOBALS['querytime']);
        $message->addMessage($_querytime);
    }

    if ($GLOBALS['is_ajax_request'] == true) {
        if ($cfg['ShowSQL']) {
            $extra_data['sql_query'] = PMA_Util::getMessage(
                $message, $GLOBALS['sql_query'], 'success'
            );
        }
        if (isset($GLOBALS['reload']) && $GLOBALS['reload'] == 1) {
            $extra_data['reload'] = 1;
            $extra_data['db'] = $GLOBALS['db'];
        }
        $response = PMA_Response::getInstance();
        $response->isSuccess($message->isSuccess());
        // No need to manually send the message
        // The Response class will handle that automatically
        $query__type = PMA_DisplayResults::QUERY_TYPE_SELECT;
        if ($analyzed_sql[0]['querytype'] == $query__type) {
            $createViewHTML = $displayResultsObject->getCreateViewQueryResultOp(
                $analyzed_sql
            );
            $response->addHTML($createViewHTML.'<br />');
        }

        $response->addJSON(isset($extra_data) ? $extra_data : array());
        if (empty($_REQUEST['ajax_page_request'])) {
            $response->addJSON('message', $message);
            exit;
        }
    }

    if ($is_gotofile) {
        $goto = PMA_securePath($goto);
        // Checks for a valid target script
        $is_db = $is_table = false;
        if (isset($_REQUEST['purge']) && $_REQUEST['purge'] == '1') {
            $table = '';
            unset($url_params['table']);
        }
        include 'libraries/db_table_exists.lib.php';

        if (strpos($goto, 'tbl_') === 0 && ! $is_table) {
            if (strlen($table)) {
                $table = '';
            }
            $goto = 'db_sql.php';
        }
        if (strpos($goto, 'db_') === 0 && ! $is_db) {
            if (strlen($db)) {
                $db = '';
            }
            $goto = 'index.php';
        }
        // Loads to target script
        if (strlen($goto) > 0) {
            $active_page = $goto;
            include '' . $goto;
        } else {
            // Echo at least one character to prevent showing last page from history
            echo " ";
        }

    } else {
        // avoid a redirect loop when last record was deleted
        if (0 == $num_rows && 'sql.php' == $cfg['DefaultTabTable']) {
            $goto = str_replace('sql.php', 'tbl_structure.php', $goto);
        }
        PMA_sendHeaderLocation(
            $cfg['PmaAbsoluteUri'] . str_replace('&amp;', '&', $goto)
            . '&message=' . urlencode($message)
        );
    } // end else
    exit();
    // end no rows returned
} else {
    $html_output='';
    // At least one row is returned -> displays a table with results
    //If we are retrieving the full value of a truncated field or the original
    // value of a transformed field, show it here and exit
    if ($GLOBALS['grid_edit'] == true) {
        $row = $GLOBALS['dbi']->fetchRow($result);
        $response = PMA_Response::getInstance();
        $response->addJSON('value', $row[0]);
        exit;
    }

    if (isset($_REQUEST['ajax_request']) && isset($_REQUEST['table_maintenance'])) {
        $response = PMA_Response::getInstance();
        $header   = $response->getHeader();
        $scripts  = $header->getScripts();
        $scripts->addFile('makegrid.js');
        $scripts->addFile('sql.js');

        // Gets the list of fields properties
        if (isset($result) && $result) {
            $fields_meta = $GLOBALS['dbi']->getFieldsMeta($result);
            $fields_cnt  = count($fields_meta);
        }

        if (empty($disp_mode)) {
            // see the "PMA_setDisplayMode()" function in
            // libraries/DisplayResults.class.php
            $disp_mode = 'urdr111101';
        }

        // hide edit and delete links for information_schema
        if ($GLOBALS['dbi']->isSystemSchema($db)) {
            $disp_mode = 'nnnn110111';
        }

        if (isset($message)) {
            $message = PMA_Message::success($message);
            $html_output .= PMA_Util::getMessage(
                $message, $GLOBALS['sql_query'], 'success'
            );
        }

        // Should be initialized these parameters before parsing
        $showtable = isset($showtable) ? $showtable : null;
        $printview = isset($_REQUEST['printview']) ? $_REQUEST['printview'] : null;
        $url_query = isset($url_query) ? $url_query : null;

        if (!empty($sql_data) && ($sql_data['valid_queries'] > 1)) {

            $_SESSION['is_multi_query'] = true;
            $html_output .= getTableHtmlForMultipleQueries(
                $displayResultsObject, $db, $sql_data, $goto,
                $pmaThemeImage, $text_dir, $printview, $url_query,
                $disp_mode, $sql_limit_to_append, false
            );
        } else {
            $_SESSION['is_multi_query'] = false;
            $displayResultsObject->setProperties(
                $unlim_num_rows, $fields_meta, $is_count, $is_export, $is_func,
                $is_analyse, $num_rows, $fields_cnt, $querytime, $pmaThemeImage,
                $text_dir, $is_maint, $is_explain, $is_show, $showtable,
                $printview, $url_query, false
            );

            $html_output .= $displayResultsObject->getTable(
                $result, $disp_mode, $analyzed_sql
            );
            $response = PMA_Response::getInstance();
            $response->addHTML($html_output);
            exit();
        }
    }

    // Displays the headers
    if (isset($show_query)) {
        unset($show_query);
    }
    if (isset($_REQUEST['printview']) && $_REQUEST['printview'] == '1') {
        PMA_Util::checkParameters(array('db', 'full_sql_query'));

        $response = PMA_Response::getInstance();
        $header = $response->getHeader();
        $header->enablePrintView();

        $html_output .= PMA_getHtmlForPrintViewHeader(
            $db, $full_sql_query, $num_rows
        );
    } else {
        $response = PMA_Response::getInstance();
        $header = $response->getHeader();
        $scripts = $header->getScripts();
        $scripts->addFile('makegrid.js');
        $scripts->addFile('sql.js');

        unset($message);

        if (! $GLOBALS['is_ajax_request']) {
            if (strlen($table)) {
                include 'libraries/tbl_common.inc.php';
                $url_query .= '&amp;goto=tbl_sql.php&amp;back=tbl_sql.php';
                include 'libraries/tbl_info.inc.php';
            } elseif (strlen($db)) {
                include 'libraries/db_common.inc.php';
                include 'libraries/db_info.inc.php';
            } else {
                include 'libraries/server_common.inc.php';
            }
        } else {
            //we don't need to buffer the output in getMessage here.
            //set a global variable and check against it in the function
            $GLOBALS['buffer_message'] = false;
        }
    }

    if (strlen($db)) {
        $cfgRelation = PMA_getRelationsParam();
    }

    // Gets the list of fields properties
    if (isset($result) && $result) {
        $fields_meta = $GLOBALS['dbi']->getFieldsMeta($result);
        $fields_cnt  = count($fields_meta);
    }

    //begin the sqlqueryresults div here. container div
    $html_output .= '<div id="sqlqueryresults"';
    $html_output .= ' class="ajax"';
    $html_output .= '>';

    // Display previous update query (from tbl_replace)
    if (isset($disp_query) && ($cfg['ShowSQL'] == true) && empty($sql_data)) {
        $html_output .= PMA_Util::getMessage($disp_message, $disp_query, 'success');
    }

    if (isset($profiling_results)) {
        // pma_token/url_query needed for chart export
        $token = $_SESSION[' PMA_token '];
        $url = (isset($url_query) ? $url_query : PMA_generate_common_url($db));

        $html_output .= PMA_getHtmlForProfilingChart(
            $url, $token, $profiling_results
        );
    }

    // Displays the results in a table
    if (empty($disp_mode)) {
        // see the "PMA_setDisplayMode()" function in
        // libraries/DisplayResults.class.php
        $disp_mode = 'urdr111101';
    }

    $has_unique = PMA_resultSetContainsUniqueKey(
        $db, $table, $fields_meta
    );

    // hide edit and delete links:
    // - for information_schema
    // - if the result set does not contain all the columns of a unique key
    //   and we are not just browing all the columns of an updatable view
    $updatableView
        = $justBrowsing
        && trim($analyzed_sql[0]['select_expr_clause']) == '*'
        && PMA_Table::isUpdatableView($db, $table);
    $editable = $has_unique || $updatableView;
    if (!empty($table) && ($GLOBALS['dbi']->isSystemSchema($db) || !$editable)) {
        $disp_mode = 'nnnn110111';
        $msg = PMA_message::notice(
            __(
                'Table %s does not contain a unique column.'
                . ' Grid edit, checkbox, Edit, Copy and Delete features'
                . ' are not available.'
            )
        );
        $msg->addParam($table);
        $html_output .= $msg->getDisplay();
    }

    if (isset($_GET['label'])) {
        $msg = PMA_message::success(__('Bookmark %s created'));
        $msg->addParam($_GET['label']);
        $html_output .= $msg->getDisplay();
    }

    // Should be initialized these parameters before parsing
    $showtable = isset($showtable) ? $showtable : null;
    $printview = isset($_REQUEST['printview']) ? $_REQUEST['printview'] : null;
    $url_query = isset($url_query) ? $url_query : null;

    if (! empty($sql_data) && ($sql_data['valid_queries'] > 1) || $is_procedure) {

        $_SESSION['is_multi_query'] = true;
        $html_output .= getTableHtmlForMultipleQueries(
            $displayResultsObject, $db, $sql_data, $goto,
            $pmaThemeImage, $text_dir, $printview, $url_query,
            $disp_mode, $sql_limit_to_append, $editable
        );
    } else {
        $_SESSION['is_multi_query'] = false;
        $displayResultsObject->setProperties(
            $unlim_num_rows, $fields_meta, $is_count, $is_export, $is_func,
            $is_analyse, $num_rows, $fields_cnt, $querytime, $pmaThemeImage,
            $text_dir, $is_maint, $is_explain, $is_show, $showtable,
            $printview, $url_query, $editable
        );

        $html_output .= $displayResultsObject->getTable(
            $result, $disp_mode, $analyzed_sql
        );
        $GLOBALS['dbi']->freeResult($result);
    }

    // BEGIN INDEX CHECK See if indexes should be checked.
    if (isset($query_type)
        && $query_type == 'check_tbl'
        && isset($selected)
        && is_array($selected)
    ) {
        foreach ($selected as $idx => $tbl_name) {
            $check = PMA_Index::findDuplicates($tbl_name, $db);
            if (! empty($check)) {
                $html_output .= sprintf(
                    __('Problems with indexes of table `%s`'), $tbl_name
                );
                $html_output .= $check;
            }
        }
    } // End INDEX CHECK

    // Bookmark support if required
    if ($disp_mode[7] == '1'
        && (! empty($cfg['Bookmark']) && empty($_GET['id_bookmark']))
        && ! empty($sql_query)
    ) {
        $html_output .= "\n";
        $goto = 'sql.php?'
              . PMA_generate_common_url($db, $table)
              . '&amp;sql_query=' . urlencode($sql_query)
              . '&amp;id_bookmark=1';
        $bkm_sql_query = urlencode(
            isset($complete_query) ? $complete_query : $sql_query
        );
        $html_output .= PMA_getHtmlForBookmark(
            $db, $goto, $bkm_sql_query, $cfg['Bookmark']['user']
        );
    } // end bookmark support

    // Do print the page if required
    if (isset($_REQUEST['printview']) && $_REQUEST['printview'] == '1') {
        $html_output .= PMA_Util::getButton();
    } // end print case
    $html_output .= '</div>'; // end sqlqueryresults div
    $response = PMA_Response::getInstance();
    $response->addHTML($html_output);
} // end rows returned

$_SESSION['is_multi_query'] = false;


if (! isset($_REQUEST['table_maintenance'])) {
    exit;
}

?>
