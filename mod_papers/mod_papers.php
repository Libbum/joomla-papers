<?php
/**
 * Papers Module Entry Point
 *
 * @package    TCQP.Papers
 * @subpackage Modules
 * @license    GNU/GPL, see LICENSE.php
 * @link       http://tcqp.science
 * mod_papers is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */

// No direct access
defined('_JEXEC') or die;
// Include the syndicate functions only once
require_once dirname(__FILE__) . '/helper.php';

$config = array(
    'orcids' => ['0000-0000-0000-0000','0000-0000-0000-0001','0000-0000-0000-0002'], //Align most sanitised to least, will prefer data from earlier in the array.
    'update_time' => 86400,    // update time (unix timestamp; 86400 is 24h)
    'start_year' => '2011',    // starting year for the publication list
);

$papers = modPapersHelper::showPapers($config);
require JModuleHelper::getLayoutPath('mod_papers');

?>
