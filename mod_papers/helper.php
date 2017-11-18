<?php
/**
 * Helper class for the Papers module
 *
 * @package    TCQP.Papers
 * @subpackage Modules
 * @link http://tcqp.papers
 * @license        GNU/GPL, see LICENSE.php
 * mod_papers is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */
class ModPapersHelper
{
    /**
     * Retrieves the required informaton from ORCID
     *
     * @param   array  $orcids An object containing the ORCIDs to be searched
     *
     * @access public
     */
    public static function getPapers($orcids)
    {
        foreach ($orcids as $id) {
            // create a new cURL resource
            $ch  = curl_init();
            // set URL and other appropriate options
            $options = array(
                CURLOPT_URL => 'http://pub.orcid.org/v1.2/' . $id . '/orcid-works',
                CURLOPT_HEADER => false,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_HTTPHEADER => array(
                    'Accept: application/orcid+json'
                )
            );
            curl_setopt_array($ch, $options);
            // grab URL and pass it to the browser
            $raw = curl_exec($ch);
            // close cURL resource, and free up system resources
            curl_close($ch);
            //Decode json data
            $data  = json_decode($raw, true);
            //Grab usefull stuff and merge
            $works = $data['orcid-profile']['orcid-activities']['orcid-works']['orcid-work'];
            if (!empty($works)) {
                if ($id === reset($orcids)) {
                    $mergedworks = $works;
                } else {
                    $mergedworks = array_merge($mergedworks, $works);
                }
            }
        }

        //Get all dois
        $dois = array();
        foreach ($mergedworks as $key => $work) {
            if (!is_null($work['work-external-identifiers'])) {
                foreach ($work['work-external-identifiers']['work-external-identifier'] as $ids) {
                    if (strcmp($ids['work-external-identifier-type'], 'DOI') == 0) {
                        $dois[] = $ids['work-external-identifier-id']['value'];
                    }
                }
                $mergedworks[$key] = array_merge($mergedworks[$key], array('parse'=>1,'udoi'=>'')); //Build a parse check field. Parse by default, set to zero if there's an issue.
            } else {
                unset($mergedworks[$key]); //For now, kill anything without a DOI.
            }
        }

        //Find all unique dois
        $udois = array_unique($dois);

        //sanitise merged array.
        foreach ($mergedworks as $mkey => $work) {
            //Identify Duplicates
            foreach ($work['work-external-identifiers']['work-external-identifier'] as $ids) {
                if (strcmp($ids['work-external-identifier-type'], 'DOI') == 0) {
                    $doi = $ids['work-external-identifier-id']['value'];
                    $mergedworks[$mkey]['udoi'] = $doi;
                    $key = array_search($doi, $udois); // Find where DOI is in the unique list

                    unset($udois[$key]); //Found one, don't need another.
                    if ($key === false) {
                        $mergedworks[$mkey]['parse'] = 0; //Don't parse this entry
                    }
                }
            }
        }

        //Sort array by year
        usort($mergedworks, function($a, $b) {
            return ($a['publication-date']['year']['value'] > $b['publication-date']['year']['value']) ? -1 : 1;
        });

        $curr_year = date("Y");
        $output = "<h2>" . $curr_year . "</h2>";
        foreach ($mergedworks as $work) {
            //Identify Results earlier than 2011
            $year = $work['publication-date']['year']['value'];
            if ($year < '2011') {
                $work['parse'] = 0; //Don't parse this entry
            } elseif ($year < $curr_year) {
                //As our list is sorted, we've moved to the previous year now. Separate the results.
                $curr_year = $year;
                $output .= "<br><h2>" . $curr_year . "</h2>";
            }
            //Print results
            if ($work['parse'] === 1) {
                $output .= '<b>' . $work['work-title']['title']['value'] . '</b><br>';
                // $doi = $ids['work-external-identifier-id']['value'];

                if (strcmp($work['work-citation']['work-citation-type'], 'BIBTEX') == 0) {
                    $bibtex = $work['work-citation']['citation'];
                    $volume = '';
                    $pages  = '';
                    if (preg_match('/volume\\s?=\\s?{(\\d+)}/', $bibtex, $match)) {
                        $volume = $match[1];
                    }
                    if (preg_match('/pages\\s?=\\s?{([0-9-]+)}/', $bibtex, $match)) {
                        $pages = $match[1];
                    }
                }

                if (!is_null($work['work-contributors'])) {
                    foreach ($work['work-contributors']['contributor'] as $authors) {
                        if (($authors === reset($work['work-contributors']['contributor'])) && ($authors === end($work['work-contributors']['contributor']))) {
                            $output .= $authors['credit-name']['value'] . '<br>';
                        } elseif ($authors === reset($work['work-contributors']['contributor'])) {
                            $output .= $authors['credit-name']['value'];
                        } elseif ($authors === end($work['work-contributors']['contributor'])) {
                            $output .= ' and ' . $authors['credit-name']['value'] . '<br>';
                        } else {
                            $output .= ', ' . $authors['credit-name']['value'];
                        }
                    }
                } else {
                    //Get authorlist from bibtex
                    if (preg_match('/author\\s?=\\s?{(.+)}/', $bibtex, $match)) {
                        $authorstr = $match[1];
                        $authors   = explode(" and ", $authorstr);
                        foreach ($authors as $author) {
                            if (($author === reset($authors)) && ($author === end($authors))) {
                                $output .= $author . '<br>';
                            } elseif ($author === reset($authors)) {
                                $output .= $author;
                            } elseif ($author === end($authors)) {
                                $output .= ' and ' . $author . '<br>';
                            } else {
                                $output .= ', ' . $author;
                            }
                        }
                    }
                }

                $output .= '<a href="http://dx.doi.org/' . $work['udoi'] . '">' . $work['journal-title']['value'] . ' <b>' . $volume . '</b> ' . $pages . ' (' . $work['publication-date']['year']['value'] . ')</a><br style="line-height:2.5em;">';
            }
        }

        return $output;
    }
}
?>
