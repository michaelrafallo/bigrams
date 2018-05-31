<?php
// Set unlimited execution to avoid interruption
ini_set('max_execution_time', -1);

//place this before any script you want to calculate time
$time1 = microtime(true); 

// Include requirements
include('db-config.php');
include('stemmer.php');


// Retrieve a list of article titles and links from a database
$agent_results = mysql_query("SELECT id, agentname, url, headline FROM ".AGENT_RESULT_DB." WHERE headline != ''");
// mysql_num_rows($agent_results);

// get codenames and store data sets into array 
$codename_results = mysql_query("SELECT code, entity FROM ".CODENAME_DB);
while($codename_result = mysql_fetch_assoc($codename_results)) {
	$codename_result_code[] = $codename_result['code'] ? $codename_result['code'] : 'Code#00000';
	$codename_result_entity[] = $codename_result['entity'] ? strtolower($codename_result['entity']) : 'undefined';
}


// From each title, remove the stopwords
$stoplist_smart = file_get_contents(STOPLIST_SMART, true);
$stopwords = array_filter( explode(' ', str_replace(array("\r", "\n"), ' ', strtolower($stoplist_smart))) );

$w=1;
while($agent_result = mysql_fetch_assoc($agent_results)) {
	
	// Remove whitespaces
	$trim_whitespaces_headline = preg_replace('/\s+/', ' ', strtolower($agent_result['headline']));

	// Replace entiry into code
	$headline = str_replace($codename_result_entity, $codename_result_code, $trim_whitespaces_headline);

	$articleid = $agent_result['id'];
	$url = $agent_result['url'];
    	
    $replace_headline = preg_replace('/\b('.implode('|',$stopwords).')\b/', '', $headline); // remove stoplist
    $trim_headline = trim(preg_replace('/\s\s+/', ' ', str_replace("\n", " ", $replace_headline))); // remove whites spaces
	$remove_special_chars = strtolower(trim( preg_replace( "/[^0-9a-z]+/i", " ", $trim_headline ) )); // remove special characters

    $ex_headline = explode(' ', $remove_special_chars);
	$headcount = count($ex_headline);

	// Do not include URL as title
	// Remove duplicates in each cluster base on link.
	if( filter_var($headline, FILTER_VALIDATE_URL) === false ) {
		for ($a=0; $a < $headcount-1; $a++) { 

			for ($b=$a+1; $b < $headcount; $b++) { 
				
				// For each title, collect and store every two word combination (these are called "bigrams") along with the article ID and link
				$word1 = PorterStemmer::Stem($ex_headline[$a]);
				$word2 = PorterStemmer::Stem($ex_headline[$b]);

				$slug = $word1.','.$word2;
			
				$token = md5($slug.$url);
			 	$data[$token]['articleid'] = $articleid;
			 	$data[$token]['source']    = $agent_result['agentname'];
			 	$data[$token]['headline']  = $agent_result['headline'];
			 	$data[$token]['title']     = $headline;
			 	$data[$token]['link']      = $url;
			 	$data[$token]['bigram']    = $word1.','.$word2;
			 	
				// Generate "clusters" of articles that share the same bigrams
			 	$words[$slug] = @$words[$slug] + 1;

				$w++;
			}
		}
	}
}

// If more than half of a cluster of articles are contained in another cluster, then merge the clusters
foreach($data as $d) {
	$clusternumber = $words[$d['bigram']];
	if( $clusternumber > 5 && $d['title'] != '') {
		$d['clusternumber'] = $clusternumber;
		$articles[] = $d;
		$clusters[$d['bigram']] = @$clusters[$d['bigram']] + 1;
	}
}

// Throw away clusters that have less than 3 articles.
foreach($articles as $art) {
	$clusternumber = $clusters[$art['bigram']];	
	if( $clusternumber > 3 ) {		
		$art['words'] = implode(',', array_unique(array_filter(explode(',', $art['bigram'].','.@$articles2[$art['articleid']]['bigram']))));
		$articles2[$art['articleid']] = $art;

		$words     = $art['words'];		
		$articleid = $art['articleid'];		
		$title     = $art['title'];		
		$link      = $art['link'];		

		// Avoid multiple entry for the same article ID
		if( ! @mysql_num_rows(mysql_query( "SELECT id FROM ".CLUSTER_DB." WHERE articleid='$articleid'")) ) {
			// Insert filtered articles into "clusters1" table
			mysql_query("INSERT IGNORE INTO ".CLUSTER_DB." (clusternumber, words, articleid, source, title, link) 
			VALUES ('$clusternumber', '$words', '$articleid', '".$art['source']."', '$title', '$link')");
		}

	}
}

// Check runtime execution
$time2 = microtime(true);
$runtime = number_format($time2 - $time1);

echo '<h1>Finished!</h1>';
echo 'Script execution time is <b>'.$runtime.' second'.($runtime>1?'s':'').'</b>'; //value in seconds

