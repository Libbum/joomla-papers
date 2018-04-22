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

    static $config;

    public static function showPapers($orcids){

        ModPapersHelper::$config = include('config.php');
        $lastRunLog =  dirname(__FILE__) . '/lastrun.log';
        $data_file = dirname(__FILE__) . '/pubs_cache_file.html';

        $lastRun = file_get_contents($lastRunLog);

        if (time() - (int)$lastRun >= ModPapersHelper::$config['update_time']) {
            //its been more than a day so run our external file
            $papers = self::getPapers($orcids);
            //update lastrun.log with current time
            file_put_contents($lastRunLog, time());
            $myfile = fopen($data_file, "w") or die("Unable to open file!");
            fwrite($myfile, $papers);
            fclose($myfile);
        } else {
            $papers = file_get_contents($data_file);	
        }

        return $papers;
    }

    private function getPapers($orcids)
    {
        // create a new cURL resource
        $ch  = curl_init();
        foreach ($orcids as $id) {
            // set URL and other appropriate options
            $options = array(
                CURLOPT_URL => 'https://pub.orcid.org/v2.1/' . $id . '/works',
                CURLOPT_HEADER => false,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_HTTPHEADER => array(
                    'Accept: application/orcid+json'
                )
            );
            curl_setopt_array($ch, $options);
            // grab URL and pass it to the browser
            $raw = curl_exec($ch);
            //Decode json data
            $data  = json_decode($raw, true);
            //Grab usefull stuff and merge
            $works = $data['group'];
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
            if (!is_null($work['external-ids'])) {
                foreach ($work['external-ids']['external-id'] as $ids) {
                    if (strcmp($ids['external-id-type'], 'doi') == 0) {
                        $dois[] = $ids['external-id-value'];
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
            //Identify Results earlier than 2011 NOTE: Thiscondition is due to our group's inception year
            $year = $work['work-summary'][0]['publication-date']['year']['value'];
            if ($year < ModPapersHelper::$config['start_year']) {
                $mergedworks[$mkey]['parse'] = 0; //Don't parse this entry
            } else {
                //Identify Duplicates
                foreach ($work['external-ids']['external-id'] as $ids) {
                    if (strcmp($ids['external-id-type'], 'doi') == 0) {
                        $doi = $ids['external-id-value'];
                        $mergedworks[$mkey]['udoi'] = $doi;
                        $key = array_search($doi, $udois); // Find where DOI is in the unique list

                        unset($udois[$key]); //Found one, don't need another
                        if ($key === false) {
                            $mergedworks[$mkey]['parse'] = 0; //Don't parse this entry
                        }
                    }
                }
            }
        }

        // Get relevant putcodes separated by orcid
        $putcodes = array_fill_keys($orcids, array());
        foreach ($mergedworks as $mkey => $work) {
            if ($mergedworks[$mkey]['parse'] != 0) {
                //path gives us the relevant orcid (1) and putcode (2). Take only the 0th index as this is the user's preferred source.
                if (preg_match('/\/([0-9\-]+)\/work\/([0-9]+)/', $work['work-summary'][0]['path'], $match)) {
                    array_push($putcodes[$match[1]], $match[2]);
                }
            }
        }

        // Get the data we require based on all of this processing
        $dataset = array();
        foreach ($putcodes as $oid => $codes) {
            $chunked_codes = array_chunk($codes, 50); // The v2 API will only accept 50 putcodes per request
            foreach ($chunked_codes as $chunk) {
                // set URL and other appropriate options
                $options = array(
                    CURLOPT_URL => 'https://pub.orcid.org/v2.1/' . $oid . '/works/' . implode(",", $chunk),
                    CURLOPT_HEADER => false,
                    CURLOPT_RETURNTRANSFER => 1,
                    CURLOPT_HTTPHEADER => array(
                        'Accept: application/orcid+json'
                    )
                );
                curl_setopt_array($ch, $options);
                // grab URL and pass it to the browser
                $raw = curl_exec($ch);
                //Decode json data and save
                $data = json_decode($raw, true);
                if (!empty($data['bulk'])) {
                    foreach ($data['bulk'] as $item) {
                        array_push($dataset, $item['work']);
                    }
                }
            }
        }

        // close cURL resource, and free up system resources
        curl_close($ch);
        //Sort array by year
        usort($dataset, function($a, $b) {
            return ($a['publication-date']['year']['value'] > $b['publication-date']['year']['value']) ? -1 : 1;
        });

        //Print results
        $curr_year = date("Y");
        $output = "<h2>" . $curr_year . "</h2>";
        foreach ($dataset as $work) {
            $year = $work['publication-date']['year']['value'];
            if ($year < $curr_year) {
                //As our list is sorted, we've moved to the previous year now. Separate the results.
                $curr_year = $year;
                $output .= "<br><h2>" . $curr_year . "</h2>";
            }
            $output .= '<b>' . $work['title']['title']['value'] . '</b><br>';

            if (strcmp($work['citation']['citation-type'], 'BIBTEX') == 0) {
                $bibtex = $work['citation']['citation-value'];
                $volume = '';
                $pages  = '';
                if (preg_match('/volume\\s?=\\s?{(\\d+)}/', $bibtex, $match)) {
                    $volume = $match[1];
                }
                if (preg_match('/pages\\s?=\\s?{([0-9-]+)}/', $bibtex, $match)) {
                    $pages = $match[1];
                }
            }

            if (!is_null($work['contributors'])) {
                foreach ($work['contributors']['contributor'] as $authors) {
                    if (($authors === reset($work['contributors']['contributor'])) && ($authors === end($work['contributors']['contributor']))) {
                        $output .= $authors['credit-name']['value'] . '<br>';
                    } elseif ($authors === reset($work['contributors']['contributor'])) {
                        $output .= $authors['credit-name']['value'];
                    } elseif ($authors === end($work['contributors']['contributor'])) {
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

            if (is_array($work['external-ids']['external-id'])) {
                foreach ($work['external-ids']['external-id'] as $ids) {
                    if (strcmp($ids['external-id-type'], 'doi') == 0) {
                        $doi = $ids['external-id-value'];
                    }
                }
            }

            $output .= '<a href="http://doi.org/' . $doi . '">' . $work['journal-title']['value'] . ' <b>' . $volume . '</b> ' . $pages . ' (' . $work['publication-date']['year']['value'] . ')</a><br style="line-height:2.5em;">';
        }

        return $output;
    }
}
?>
