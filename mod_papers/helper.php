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

	public static function getPapers($orcids){
		$lastRunLog =  dirname(__FILE__) . '/lastrun.log';
		$data_file = dirname(__FILE__) . '/pubs.html';
		$lastRun = file_get_contents($lastRunLog);
		if (time() - (int)$lastRun >= 86400) {
			//its been more than a day so run our external file
			$papers = self::genPapers($orcids);
			//update lastrun.log with current time
			file_put_contents($lastRunLog, time());
		} else {
			$papers = file_get_contents($data_file);	
		}

		return $papers;
	}

	public static function getPapersForYearsBatch($years, $mergedworks){

		// array of curl handles
		$multiCurl = array();
		$mh  = curl_multi_init();
		$id = 0;

		foreach ($mergedworks as $work) {
			//Identify Results earlier than 2011
			$year = $work['work-summary']['0']['publication-date']['year']['value'];

			//Print results
			if ($work['parse'] === 1 && in_array($year, $years)) {

				// create a new cURL resource
				// set URL and other appropriate options
				$ch  = curl_init();
				$options = array(
					CURLOPT_URL => 'https://pub.orcid.org/v2.1' . $work['work-summary']['0']['path'],
					CURLOPT_HEADER => false,
					CURLOPT_RETURNTRANSFER => 1,
					CURLOPT_HTTPHEADER => array(
						'Accept: application/orcid+json'
					)
				);

				curl_setopt_array($ch, $options);
				curl_multi_add_handle($mh, $ch);
				$multiCurl[$id] = $ch;
				$id = $id + 1;
			}
		}

		// While we're still active, execute curl
		$active = null;
		do {
			$mrc = curl_multi_exec($mh, $active);
		} while ($mrc == CURLM_CALL_MULTI_PERFORM);

		while ($active && ($mrc == CURLM_OK)) {
			// Wait for activity on any curl-connection
			if (curl_multi_select($mh) == -1) {
                        	usleep(300);
			}
			//if (curl_multi_select($mh) != -1) {
			

				// Continue to exec until curl is ready to
				// give us more data
				do {
					$mrc = curl_multi_exec($mh, $active);
				} while ($mrc == CURLM_CALL_MULTI_PERFORM);
			//}
		}

		// get content and remove handles
		foreach($multiCurl as $k => $ch) {
			$mdata[$k] = json_decode(curl_multi_getcontent($ch), true);
			curl_multi_remove_handle($mh, $ch);
		}
		// close
		curl_multi_close($mh);
		curl_close($ch);
		return $mdata;
	}

	public static function getWorkSummaries($orcids){
	
		// array of curl handles
		$multiCurl = array();
		// data to be returned
		$mdata = array();

		$mh  = curl_multi_init();
		$counter = 0;
		foreach ($orcids as $id) {

			// create a new cURL resource
			$ch  = curl_init();
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
			curl_multi_add_handle($mh, $ch);
			$multiCurl[$id] = $ch;
			$counter = $counter + 1;
		}


		// While we're still active, execute curl
		$active = null;
		do {
			$mrc = curl_multi_exec($mh, $active);
                        usleep(300);
		} while ($mrc == CURLM_CALL_MULTI_PERFORM);

		while ($active && $mrc == CURLM_OK) {
			// Wait for activity on any curl-connection
			if (curl_multi_select($mh) == -1) {
                        	usleep(300);
			}
				// Continue to exec until curl is ready to
				// give us more data
				do {
					$mrc = curl_multi_exec($mh, $active);
				} while ($mrc == CURLM_CALL_MULTI_PERFORM);
			
		}

		// get content and remove handles
		$mergedworks = array();
		foreach($multiCurl as $k => $ch) {
			$mdata[$k] = json_decode(curl_multi_getcontent($ch), true);
			curl_multi_remove_handle($mh, $ch);
			$works = $mdata[$k]['group'];
			if (!empty($works)) {
				$mergedworks = array_merge($mergedworks, $works);
			}
		}
		// close
		curl_multi_close($mh);
		curl_close($ch);
	        return $mergedworks;
	}

	public static function genPapers($orcids)
	{

	        $mergedworks = self::getWorkSummaries($orcids);
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
			//Identify Duplicates
			foreach ($work['external-ids']['external-id'] as $ids) {
				if (strcmp($ids['external-id-type'], 'doi') == 0) {
					$doi = $ids['external-id-value'];
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
			return ($a['work-summary']['0']['publication-date']['year']['value'] > $b['work-summary']['0']['publication-date']['year']['value']) ? -1 : 1;
		});

		$curr_year = date("Y");
		$output = "<h2>" . $curr_year . "</h2>";

		// data to be returned
		$mdata = array();


               $years_batches = array(array('2018', '2017', '2016'), array('2015'), array('2014'), array('2013'), array(2012));
               //$years_batches = array(array('2018', '2017', '2016'));
               //$years_batches = array(array('2018', '2017', '2016', '2015', '2014', '2013', 2012));

		foreach ($years_batches as $years){
			$papers = self::getPapersForYearsBatch($years, $mergedworks);
		        $mdata = array_merge($mdata, $papers);
		}


		foreach ($mdata as $data) {
			$year = $data['publication-date']['year']['value'];
			if (!$year==null){
				if ((int)$year < $curr_year) {
					//As our list is sorted, we've moved to the previous year now. Separate the results.
					$curr_year = $year;
					$output .= "<br><h2>" . $curr_year . "</h2>";
				}
				$output .= '<b>' . $data['title']['title']['value'] . '</b><br>';
				// $doi = $ids['work-external-identifier-id']['value'];

				if (strcmp($data['citation']['citation-type'], 'BIBTEX') == 0) {
					$bibtex = $data['citation']['citation-value'];
					$volume = '';
					$pages  = '';
					if (preg_match('/volume\\s?=\\s?{(\\d+)}/', $bibtex, $match)) {
						$volume = $match[1];
					}
					if (preg_match('/pages\\s?=\\s?{([0-9-]+)}/', $bibtex, $match)) {
						$pages = $match[1];
					}
				}

				if (preg_match('/author\\s?=\\s?{(.+)}/', $bibtex, $match)) {
					$authorstr = $match[1];
					$ind = strpos($authorstr, '}');
					if (is_int($ind)){
						$authorstr = substr($authorstr, 0, $ind);
					}
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

					$output .= '<a href="http://dx.doi.org/' . $data['external-ids']['external-id'][0]['external-id-value'] . '">' . $data['journal-title']['value'] . ' <b>' . $volume . '</b> ' . $pages . ' (' . $data['publication-date']['year']['value'] . ')</a><br style="line-height:2.5em;">';
				}
			}
		}
		$myfile = fopen(dirname(__FILE__) . '/pubs.html', "w") or die("Unable to open file!");
		fwrite($myfile, $output);
		fclose($myfile);
		return $output;
	}
}
?>
