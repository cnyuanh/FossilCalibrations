<?php
   /* This page will use different data and behavior, depending on the circumstances under which it's being loaded:
    * 	> "inline" in edit_calibration.php, during initial page load
    *
    * 	> via AJAX fetch, when a new fossil has been added to this calibration;
    * 	  show basic stub only, hide full panel
    *
    * 	> via AJAX fetch, to retrieve all properties of an existing fossil
    * 	  after its identifier has been entered, or fresh panel for a new one;
    * 	  hide stub, show full panel
    *
    * So this page can vary based on several questions:
    *
    *   > Is this a new fossil for THIS calibration, or an existing one?
    *
    *   > Is this a new fossil used in OTHER calibrations?
    *
    *   > Is this the initial (stub) display of a new fossil, or its full panel?
    *
    * NOTE that this page does not go to great lengths to protect user input,
    * since the user is already a logged-in administrator.
    */
   require_once('../FCD-helpers.php');

   // open and load site variables
   require_once('../../config.php');

   // provide sensible defaults for data, if not provided
   $newFossilForThisCalibration = false;
   if (!isset($isLastFossil)) {
      $newFossilForThisCalibration = true;
      // connect to mySQL server and select the Fossil Calibration database
      $connection=mysql_connect($SITEINFO['servername'],$SITEINFO['UserName'], $SITEINFO['password']) or die ('Unable to connect!');
      mysql_select_db('FossilCalibration') or die ('Unable to select database!');

      $CalibrationID = $_POST['calibrationID'];

      $matchingFossilFound = false;
      if (isset($_POST['matchCollectionAcro']) && isset($_POST['matchCollectionNumber'])) {
         // try to find a matching fossil (if not, they're adding a new one)
         $fossilIdentifier = $_POST['matchCollectionAcro'] .' '. $_POST['matchCollectionNumber'];

         $query = "SELECT * FROM fossils
                   WHERE 
                       CollectionAcro = '". mysql_real_escape_string($_POST['matchCollectionAcro']) ."' AND 
                       CollectionNumber = '". mysql_real_escape_string($_POST['matchCollectionNumber']) ."'";

	 $matching_fossil =mysql_query($query) or die ('Error  in query: '.$query.'|'. mysql_error());
	 if (mysql_num_rows($matching_fossil) > 0) {
		 // YES, there's a matching fossil. Has it been associated with one or more calibrations?
		 $matchingFossilFound = true;
		 $fossil_data=mysql_fetch_assoc($matching_fossil);

		 // retrieve fossil collection
		 $query="SELECT * FROM L_CollectionAcro WHERE Acronym = '".$fossil_data['CollectionAcro']."'";
		 // TODO: force uniqueness of Acronym field here!?
		 $result=mysql_query($query) or die ('Error  in query: '.$query.'|'. mysql_error());
		 $collection_data = mysql_fetch_assoc($result);
		 mysql_free_result($result);

		 // retrieve fossil locality
		 $query="SELECT * FROM localities WHERE LocalityID = '".$fossil_data['LocalityID']."'";
		 $result=mysql_query($query) or die ('Error  in query: '.$query.'|'. mysql_error());
		 $locality_data = mysql_fetch_assoc($result);
		 mysql_free_result($result);

		 // retrieve fossil pub
		 $query="SELECT * FROM publications WHERE PublicationID = '".$fossil_data['FossilPub']."'";
		 $result=mysql_query($query) or die ('Error  in query: '.$query.'|'. mysql_error());
		 $fossil_pub_data = mysql_fetch_assoc($result);
		 mysql_free_result($result);

		 // other properties are unique to each linked calibration (TBD)
		 $fossil_species_data = null;
		 $phylo_pub_data = null;
	 }
      } else {
	      $fossilIdentifier = ' ';
      }

      if (!$matchingFossilFound) {
	      $fossil_data = null;
	      $fossil_species_data = null;
	      $locality_data = null;
	      $collection_data = null;
	      $fossil_pub_data = null;
	      $phylo_pub_data = null;
      }

      // provide other sensible defaults where needed
      $i = isset($_POST['position']) ? $_POST['position'] : 0;
      $isLastFossil = true;
      $isFirstFossil = ($i == 0);
      $totalFossils = isset($_POST['totalFossils']) ? $_POST['totalFossils'] : 1;

      //Retrieve list of localities
      $query='SELECT * FROM View_Localities ORDER BY LocalityName';
      $locality_list=mysql_query($query) or die ('Error  in query: '.$query.'|'. mysql_error());

      // list of all collection acronyms
      $query='SELECT * FROM L_CollectionAcro ORDER BY Acronym';
      $collectionacro_list=mysql_query($query) or die ('Error  in query: '.$query.'|'. mysql_error());

      //Retrieve list of age types
      $query='SELECT * FROM L_agetypes';
      $agetypes_list=mysql_query($query) or die ('Error  in query: '.$query.'|'. mysql_error());

      //Retrieve list of phylogenetic justification types
      $query='SELECT * FROM L_PhyloTypes ORDER BY PhyloJustType';
      $phyjusttype_list=mysql_query($query) or die ('Error  in query: '.$query.'|'. mysql_error());

      //Retrieve list of geological times (hierarchy is Period, Epoch, Age)
      $query='SELECT DISTINCT GeolTimeID, Period, Epoch, Age, t.ShortName, StartAge FROM geoltime g, L_timescales t WHERE g.Timescale=t.TimescaleID ORDER BY StartAge DESC, Age, Epoch;';
      $geoltime_list=mysql_query($query) or die ('Error  in query: '.$query.'|'. mysql_error());

      //Retrieve list of countries
      $query='SELECT name FROM L_countries ORDER BY name';
      $country_list=mysql_query($query) or die ('Error  in query: '.$query.'|'. mysql_error());

      //Retrieve list of relative locations
      $query='SELECT * FROM L_FossilRelativeLocation';
      $relative_location_list=mysql_query($query) or die ('Error  in query: '.$query.'|'. mysql_error());

   }

?>
<div id="fossil-header-<?= $i ?>" class="single-fossil-header" style="">
<b id="fossil-name-<?= $i ?>"><?= ($fossilIdentifier == ' ') ? 'Unidentified' : $fossilIdentifier ?></b> 
<? /* if (!$isFirstFossil) { */ ?>
<input type="button" style="float: right; position: relative; top: -3px; font-size: 0.8em;" value="delete" onclick="deleteFossil(<?= $i ?>); return false;"/>
<? /* } */ ?>
</div>
<div id="fossil-panel-<?= $i ?>" class="single-fossil-panel" style="">
<!-- add to a single array of all included fossil positions (ordinal positions in page, *NOT* database IDs) -->
<input type="hidden" name="fossil_positions[]" value="<?= $i ?>" />


<?php  
/* IF a "new" fossil has already been associated with other calibrations, suggest
 * the species names (and other "soft" properties) already assigned to it. The
 * default value in each case will be based on the first related link.
 * 
 * Returning to a fossil that's already in this calibration hides these values.
 */
$fossilAlreadyInUse = false;
$showPreviouslyAssignedValues = false;
$existing_links_data = Array();
$query = "SELECT * FROM Link_CalibrationFossil WHERE FossilID = '". mysql_real_escape_string(testForProp($fossil_data, 'FossilID', 'NEW')) ."'";  // 'NEW' should match nothing
$previousLinks = mysql_query($query) or die ('Error  in query: '.$query.'|'. mysql_error());
if(mysql_num_rows($previousLinks) > 0) { 
	$fossilAlreadyInUse = true;
	while($row=mysql_fetch_assoc($previousLinks)) { 
		$existing_links_data[] = $row;
	}
}
if ($newFossilForThisCalibration && $fossilAlreadyInUse) {
	$showPreviouslyAssignedValues = true;
}

/* The initial display for a new fossil is a "STUB" panel with just inputs for collection acronym + number. Once those
 * are submitted, we show the "full" panel instead
 */
$panelDisplay = 'STUB';  // or 'FULL'
///if ($fossilAlreadyInUse) {
if ($fossilIdentifier != ' ') {
	$panelDisplay = 'FULL';
}

if ($panelDisplay == 'STUB') { 
	// show editable widgets for collection acronym and number 
?>
<p><input type="radio" name="newOrExistingCollectionAcronym-<?= $i ?>" value="EXISTING" id="existingCollectionAcronym-<?=$i?>" checked="checked"> <label for="existingCollectionAcronym-<?=$i?>">Choose an existing <b>collection</b></label></input></p>
<table id="pick-existing-collection-acronym-<?=$i?>" width="100%" border="0">
<tr>
<td width="30%" align="right" valign="top"><strong>collection acronym</strong></td>
<td width="70%"><select name="CollectionAcro-<?= $i ?>" id="CollectionAcro-<?=$i?>">
<?php
	if(mysql_num_rows($collectionacro_list)==0){
		?>
			<option value="0">No acronyms in database, add one below.</option>
			<?php
	} else {
		mysql_data_seek($collectionacro_list,0);
		$currentCollection = testForProp($fossil_data, 'CollectionAcro', '');
		while($row=mysql_fetch_assoc($collectionacro_list)) {
			$thisCollection = $row['Acronym'];
			if ($currentCollection == $thisCollection) {
				echo '<option value="'.$row['Acronym'].'" selected="selected">'.$row['Acronym'].', '.$row['CollectionName'].'</option>';
			} else {
				echo '<option value="'.$row['Acronym'].'">'.$row['Acronym'].', '.$row['CollectionName'].'</option>';
			}			
			//echo "<option value=\"".$row['Acronym']."\">".$row['Acronym'].", ".$row['CollectionName']."</option>";
		}
	} ?>
</select>
</tr>
</table>
<p><input type="radio" name="newOrExistingCollectionAcronym-<?= $i ?>" value="NEW" id="newCollectionAcronym-<?=$i?>"> <label for="newCollectionAcronym-<?=$i?>">... <b>or</b> enter a new collection acronym into the database</label></input></p>
<table id="enter-new-collection-acronym-<?=$i?>" class="add-form" width="100%" border="0">
<tr>
<td align="right" valign="top" width="30%"><strong>new acronym</strong></td>
<td align="left" width="70%"><input type="text" name="NewAcro-<?= $i ?>" id="NewAcro-<?=$i?>" size="5" ></td>
</tr>
<tr>
<td align="right" valign="top" width="30%"><strong>new institution</strong></td>
<td align="left" width="70%"><input type="text" name="NewInst-<?= $i ?>" id="NewInst-<?=$i?>" ></td>
</tr>
</table>

<hr/>

<table width="100%" border="0">
<tr>
<td align="right" valign="top" width="30%"><strong>collection number</strong></td>
<td align="left" width="70%" style="padding-bottom: 8px;">
<input type="text" name="CollectionNum-<?= $i ?>" id="CollectionNum-<?=$i?>" value="<?= testForProp($fossil_data, 'CollectionNumber', '') ?>">
</td>
</tr>
</table>

<? } else { 
	// show dumb display (but valid hidden form widgets!) for collection acrynym and number 
	$newOrExistingCollection = isset($_POST["newOrExistingCollection"]) ? $_POST["newOrExistingCollection"] : 'EXISTING';
	if (testForProp($fossil_data, 'CollectionAcro', '') == '') {
		$existingCollectionAcro = $_POST["matchCollectionAcro"];
		$newCollectionAcro = $_POST["matchCollectionAcro"];
	} else {
		$existingCollectionAcro = testForProp($fossil_data, 'CollectionAcro', 'NEVER_CHOSEN');
		$newCollectionAcro = testForProp($fossil_data, 'CollectionAcro', 'NEVER_CHOSEN');
	}
	if (testForProp($fossil_data, 'CollectionNumber', '') == '') {
		$collectionNumber = $_POST["matchCollectionNumber"];
	} else {
		$collectionNumber = testForProp($fossil_data, 'CollectionNumber', 'NEVER_CHOSEN');
	}
	// allow for empty collection name, at least for now
	if (testForProp($collection_data, 'CollectionName', '_IMPLAUSIBLE_VALUE_') == '_IMPLAUSIBLE_VALUE_') {
		$newCollectionInst = $_POST["newCollectionInstitution"];
	} else {
		$newCollectionInst = testForProp($collection_data, 'CollectionName', 'NEVER_CHOSEN');
	}
	// if find the collection/institution name to show for this stub display
	$existingCollectionInst = null;
	if ($newOrExistingCollection == 'EXISTING') {
		// scan all collections for a match
		mysql_data_seek($collectionacro_list,0);
		$currentCollection = testForProp($fossil_data, 'CollectionAcro', '');
		while($row=mysql_fetch_assoc($collectionacro_list)) {
			$thisCollection = $row['Acronym'];
			if ($currentCollection == $thisCollection) {
				$existingCollectionInst = $row['CollectionName'];
			}			
		}
	}
?>
<input type="hidden" name="newOrExistingCollectionAcronym-<?= $i ?>" value="<?= $newOrExistingCollection ?>" />
<input type="hidden" name="CollectionAcro-<?= $i ?>" id="CollectionAcro-<?= $i ?>" value="<?= $existingCollectionAcro ?>" />
<input type="hidden" name="NewAcro-<?= $i ?>" id="NewAcro-<?= $i ?>" value="<?= $newCollectionAcro ?>" />
<input type="hidden" name="NewInst-<?= $i ?>" value="<?= $newCollectionInst ?>" />
<input type="hidden" name="CollectionNum-<?= $i ?>" id="CollectionNum-<?= $i ?>" value="<?= $collectionNumber ?>" />

<table style="margin-bottom: 8px;">
	<tr>
		<td valign="top" align="right">
			<b><?= $newOrExistingCollection ?> collection</b>
			&nbsp;
		</td>
		<td valign="top">
			<i>
				<?= ($newOrExistingCollection == 'NEW') ? $newCollectionAcro : $existingCollectionAcro ?>, 
				<?= ($newOrExistingCollection == 'NEW') ? $newCollectionInst : $existingCollectionInst ?>
			</i>
		</td>
	</tr>
	<tr>
		<td valign="top" align="right">
			<b>collection number</b> 
			&nbsp;
		</td>
		<td valign="top">
			<i><?= $collectionNumber ?></i>
		</td>
	</tr>
</table>

<? } ?>





<div id="fossil-prompt-<?= $i ?>" style="padding: 5px 8px; text-align: center; background-color: #ffd; <?= $panelDisplay == 'STUB' ? 'display: block;' : 'display: none;' ?>">
<i>Submit these values to match an existing fossil, or to create a new one.</i>
&nbsp; 
<input type="submit" value="Submit" onclick="fetchMatchingFossilProperties(this); return false;" />
</div>

<div id="fossil-properties-<?= $i ?>" style="<?= $panelDisplay == 'FULL' ? 'display: block; Xborder: 2px dashed green;' : 'display: none; Xborder: 2px dashed red;' ?>">
<input type="hidden" name="fossilID-<?= $i ?>" value="<?= testForProp($fossil_data, 'FossilID', 'NEW') ?>" />
<input type="hidden" name="fossilCalibrationLinkID-<?= $i ?>" value="<?= testForProp($fossil_data, 'FCLinkID', 'NEW') ?>" />

<div class="core-properties" style="background-color: #ffd; overflow: hidden; border-right: 12px solid #ffa;">
<h4 style="margin: 0; padding: 4px 8px; background-color: #ffa;">Changes to core fossil properties (in yellow) will appear in all calibrations!</h4>

<? if ($showPreviouslyAssignedValues) { ?>
	<p>
		<input type="radio" name="newOrExistingLocality-<?= $i ?>" value="ASSIGNED" id="assignedLocality-<?=$i?>" checked="checked" /> 
		<label for="assignedLocality-<?=$i?>">Keep the <b>previously assigned locality</b></label>
		&nbsp;
	<? $query = "SELECT LocalityName, Age FROM View_Localities WHERE LocalityID = '". mysql_real_escape_string(testForProp($fossil_data, 'LocalityID', 'NEW')) ."'";  // 'NEW' should match nothing
	$result = mysql_query($query) or die ('Error  in query: '.$query.'|'. mysql_error());
	$assigned_loc = mysql_fetch_assoc($result);
	mysql_free_result($result); ?>
		<span style="background-color: #eee; padding: 5px 7px;"><?= $assigned_loc['LocalityName'] ?>, <?= $assigned_loc['Age'] ?></span>
		<input type="hidden" name="PreviouslyAssignedLocality-<?=$i?>" value="<?= testForProp($fossil_data, 'LocalityID', 'NEW') ?>" />
		</p>
		<? } ?>

		<p><input type="radio" name="newOrExistingLocality-<?= $i ?>" value="EXISTING" id="existingLocality-<?=$i?>" <?= $showPreviouslyAssignedValues ? '' : 'checked="checked"' ?>> 
		<label for="existingLocality-<?=$i?>">Choose an existing <b>locality</b></label></input></p>
		<table id="pick-existing-locality-<?=$i?>" width="100%" border="0">
		<tr>
		<td width="30%" align="right" valign="top"><strong>locality</strong></td>
		<td width="70%"><select name="Locality-<?= $i ?>" id="Locality-<?=$i?>">
		<?php
		if(mysql_num_rows($locality_list)==0){
			echo "<option value=\"New\">Add a new formation below</option>";
		} else {
			mysql_data_seek($locality_list,0);
			$currentLocality = testForProp($fossil_data, 'LocalityID', '');
			while($row=mysql_fetch_assoc($locality_list)) {
				$thisLocality = $row['LocalityID'];
				$thisLabel = empty($row['LocalityName']) ? 'NO NAME' : $row['LocalityName'];
				if (!empty($row['Stratum'])) {
					$thisLabel = $thisLabel .' ['. $row['Stratum'] .']';
				}
				if ($currentLocality == $thisLocality) {
					echo '<option value="'.$row['LocalityID'].'" selected="selected">'.$thisLabel.'</option>';
				} else {
					echo '<option value="'.$row['LocalityID'].'">'.$thisLabel.'</option>';
				}			
			}
			//echo "<option value=\"New\">Add new locality on next page</option>";
		} ?>
</select>
</tr>
</table>
<p><input type="radio" name="newOrExistingLocality-<?= $i ?>" value="NEW" id="newLocality-<?=$i?>"> <label for="newLocality-<?=$i?>">... <b>or</b> enter a new locality into the database</label></input></p>
<table id="enter-new-locality-<?=$i?>" class="add-form" width="100%" border="0">
<tr>
<td width="30%" align="right" valign="top"><b>locality name</b></td>
<td width="70%" ><input type="text" name="LocalityName-<?= $i ?>" id="LocalityName-<?=$i?>"></td>
</tr>
<tr>
<td width="30%" align="right" valign="top"><b>stratum name</b></td>
<td width="70%" ><input type="text" name="Stratum-<?= $i ?>" id="Stratum-<?=$i?>"></td>
</tr>
<tr>
<td align="right" valign="top" width="30%"><strong>PBDB collection num</strong></td>
<td align="left" width="70%"><input type="text" name="PBDBNum-<?= $i ?>" id="PBDBNum-<?=$i?>" ></td>
</tr>
<tr>
<td align="right" valign="top" width="30%"><strong>locality notes</strong></td>
<td align="left" width="70%"><textarea name="LocalityNotes-<?= $i ?>" id="LocalityNotes-<?=$i?>" cols="50" rows="5"></textarea></td>
</tr>
<tr>
<td align="right" valign="top"><strong>country</strong></td>
<td><select name="Country-<?= $i ?>" id="Country-<?=$i?>">
<?php
if(mysql_num_rows($country_list)==0){
	echo "no countries available";
} else {
	mysql_data_seek($country_list,0);
	while($row=mysql_fetch_assoc($country_list)) {
		echo "<option value=\"".$row['name']."\">".$row['name']."</option>";
	}
}
?>
</select>
</tr>
<tr>
<td align="right" valign="top"><strong>geological age</strong></td>
<td><select name="GeolTime-<?= $i ?>" id="GeolTime-<?=$i?>">
<?php
if(mysql_num_rows($geoltime_list)==0){
	?>
		<option value="0">No geological time in database</option>
		<?php
} else {
	mysql_data_seek($geoltime_list,0);
	while($row=mysql_fetch_assoc($geoltime_list)) {
		echo "<option value=\"".$row['GeolTimeID']."\">".$row['Period'];
		if ($row['Epoch']) {
			echo " / ".$row['Epoch'];
			if ($row['Age']) {
				echo " / ".$row['Age'];
			};
		};
		echo "</option>";
	}

}
?>
</select>
</tr>
</table>

<hr/>

<? if ($showPreviouslyAssignedValues) { ?>
	<p>
		<input type="radio" name="newOrExistingFossilPublication-<?= $i ?>" value="ASSIGNED" id="assignedFossilPublication-<?=$i?>" checked="checked" /> 
		<label for="assignedFossilPublication-<?=$i?>">Keep the <b>previously assigned fossil publication</b></label>
	</p>
	<table width="100%">
		<tr>
		<td>
		<div class="text-excerpt PreviouslyAssignedFossilPubFullReference" style="margin-top: -8px; margin-left: 20px;"><?= testForProp($fossil_pub_data, 'FullReference', '&nbsp;') ?></div>
	    <input type="hidden" name="PreviouslyAssignedFossilPub-<?=$i?>" value="<?= testForProp($fossil_pub_data, 'PublicationID', '') ?>" />
	    <input type="hidden" class="PreviouslyAssignedFossilPubShortName" value="<?= testForProp($fossil_pub_data, 'ShortName', '') ?>" />
		</td>
		</tr>
	</table>
<? } ?>

	<p><input type="radio" name="newOrExistingFossilPublication-<?= $i ?>" value="EXISTING" id="existingFossilPublication-<?=$i?>" <?= $showPreviouslyAssignedValues ? '' : 'checked="checked"' ?>>
	<label for="existingFossilPublication-<?=$i?>">Choose an existing <b>fossil publication</b></label></input></p>
	<table id="pick-existing-fossil-pub-<?=$i?>" width="100%" border="0">
		<tr>
		<td width="25%" align="right" valign="top"><b>enter partial name</b></td>
		<td width="75%">
		<input type="text" name="AC_FossilPubID-display-<?= $i ?>" id="AC_FossilPubID-display-<?=$i?>" value="<?= testForProp($fossil_pub_data, 'ShortName', '') ?>" />
		<input type="text" name="FossilPub-<?= $i ?>" id="AC_FossilPubID-<?=$i?>" value="<?= testForProp($fossil_pub_data, 'PublicationID', '') ?>" readonly="readonly" style="width: 30px; color: #999; text-align: center;"/>
		<a href="/protected/manage_publications.php" target="_new" style="float: right;">Show all publications in a new window</a>
		<div id="AC_FossilPubID-more-info-<?=$i?>" class="text-excerpt"><?= testForProp($fossil_pub_data, 'FullReference', '&nbsp;') ?></div>
		</td>
		</tr>
	</table>
	<p><input type="radio" name="newOrExistingFossilPublication-<?= $i ?>" value="NEW" id="newFossilPublication-<?=$i?>"> <label for="newFossilPublication-<?=$i?>">... <b>or</b> enter a new publication into the database</label></input></p>
	<table id="enter-new-fossil-pub-<?=$i?>" class="add-form" width="100%" border="0">
		<tr>
		<td align="right" valign="top" width="30%"><strong>short form (author, date)</strong></td>
		<td align="left" width="70%"><input type="text" name="FossShortForm-<?= $i ?>" id="FossShortForm-<?=$i?>" size="10"></td>
		</tr>
		<tr>
		<td align="right" valign="top" width="30%"><strong>full citation</strong></td>
		<td align="left" width="70%"><input type="text" name="FossFullCite-<?= $i ?>" id="FossFullCite-<?=$i?>" style="width: 95%;"></td>
		</tr>
		<tr>
		<td align="right" valign="top" width="30%"><strong>doi (or other url)</strong></td>
		<td align="left" width="70%"><input type="text" name="FossDOI-<?= $i ?>" id="FossDOI-<?=$i?>" size="10"></td>
		</tr>
	</table>

</div><!-- END of .core-properties -->
		<hr style="margin-top: 0;"/>

		<? if ($showPreviouslyAssignedValues) { ?>
			<p>
				<input type="radio" name="newOrExistingFossilSpecies-<?= $i ?>" value="ASSIGNED" id="assignedFossilSpecies-<?=$i?>" checked="checked" /> 
				<label for="assignedFossilSpecies-<?=$i?>">Re-use a <b>previously assigned species</b></label>
				&nbsp;
			<select name="PreviouslyAssignedSpeciesName-<?= $i ?>" id="PreviouslyAssignedSpeciesName-<?= $i ?>">
				<? foreach($existing_links_data as $row) { ?>
					<option value="<?=$row['Species']?>"><?=$row['Species']?></option>
						<? } ?>
						</select>
						</p>
						<? } ?>

						<p><input type="radio" name="newOrExistingFossilSpecies-<?= $i ?>" value="EXISTING" id="existingFossilSpecies-<?=$i?>" <?= $showPreviouslyAssignedValues ? '' : 'checked="checked"' ?>> 
						<label for="existingFossilSpecies-<?=$i?>">Choose an <b>existing species</b> or lower taxon</label></input></p>
						<table id="pick-existing-fossil-species-<?=$i?>" width="100%" border="0">
						<tr style="background-color: #eee;">
						<td align="right" valign="top" width="30%" style="background-color: #eee; color: #888;"><strong>search all existing taxa...</strong></td>
						<td align="left" width="70%" style="background-color: #eee;">
						<!-- <input type="text" name="SpeciesName" id="SpeciesName" style="width: 280px;" value=""> -->
						<input type="text" name="AC_FossilSpeciesID-display-<?= $i ?>" id="AC_FossilSpeciesID-display-<?=$i?>" value="<?= testForProp($fossil_data, 'Species', '') ?>" style="width: 45%;"/>
						<? // stash the ID of the matching fossil-species record (from table fossiltaxa), to make sure we're updating the same record ?>
						<input type="text" name="ExistingFossilSpeciesID-<?= $i ?>" id="AC_FossilSpeciesID-<?=$i?>" value="<?= testForProp($fossil_species_data, 'TaxonID', 0) ?>" readonly="readonly" style="width: 45%; color: #999; text-align: center;"/>
						</td>
						</tr>
						<? /* Fuzzy matching against entered species name...
						      <tr>
						      <td width="70%" align="left" valign="top"><select name="SpeciesID" id="SpeciesID">

						      <?php
						      $query = "SELECT *,MATCH(TaxonName, CommonName) AGAINST ('".$_POST['SpeciesName']."') AS score FROM `fossiltaxa` WHERE MATCH(TaxonName, CommonName) AGAINST ('".$_POST['SpeciesName']."' IN NATURAL LANGUAGE MODE) ORDER BY score DESC";
						      $close_matches=mysql_query($query) or die ('Error  in query: '.$query.'|'. mysql_error());
						      if(mysql_num_rows($close_matches)==0) { echo "<option value=\"New\" id=\"New\">no exact match. choose a species from list or enter new taxon below.</option>"; } 
						      else {
						      while($row=mysql_fetch_assoc($close_matches)) {
						      ?>
						      <option value="<?=$row['TaxonID']?>" id="<?=$row['TaxonID']?>" /><i><?=$row['TaxonName']?></i> <?=$row['TaxonAuthor']?> (<?=$row['CommonName']?>)</option>
						      <?php
						      }
						      }
						      ?>
						      </select>
						      </td></tr>
						    */ ?>
						<tr>
						<td width="30%" align="right" valign="top"><strong>scientific name</strong></td><td width="70%" align="left" valign="top">
						<input name="ExistingSpeciesName-<?= $i ?>" type="text" readonly="readonly" value="<?= testForProp($fossil_species_data, 'TaxonName', '') ?>" />
						<em id="species-matched-from-<?=$i?>">This name is not editable; instead, enter a new species below.</em>
						</td>
						</tr>
						<tr>
						<td width="30%" align="right" valign="top"><strong>common name</strong></td><td width="70%" align="left" valign="top">
						<input name="ExistingSpeciesCommonName-<?= $i ?>" type="text" value="<?= testForProp($fossil_species_data, 'CommonName', '') ?>" />
						</td>
						</tr>
						<tr>
						<td width="30%" align="right" valign="top"><strong>author and date</strong></td><td width="70%" align="left" valign="top">
						<input name="ExistingSpeciesAuthor-<?= $i ?>" type="text" value="<?= testForProp($fossil_species_data, 'TaxonAuthor', '') ?>" />
						<em id="author-matched-from-<?=$i?>">&nbsp;</em>
						</td>
						</tr>
						<tr>
						<td width="30%" align="right" valign="top"><strong>PaleoDB taxon number</strong></td><td width="70%" align="left" valign="top">
						<input name="ExistingSpeciesPBDBTaxonNum-<?= $i ?>" type="text" value="<?= testForProp($fossil_species_data, 'PBDBTaxonNum', '') ?>" />
						</td>
						</tr>
						<tr>
						<td width="30%" align="right" valign="top">&nbsp;</td><td width="70%" align="left" valign="top">
						<em>Changes above will be reflected in all calibrations of this fossil species!</em>
						</td>
						</tr>
						</table>

						<p><input type="radio" name="newOrExistingFossilSpecies-<?= $i ?>" value="NEW" id="newFossilSpecies-<?=$i?>"> <label for="newFossilSpecies-<?=$i?>">... <b>or</b> enter a new taxon into the database</label></input></p>
						<table id="enter-new-fossil-species-<?=$i?>" class="add-form" width="100%" border="0">
						<tr>
						<td width="30%" align="right" valign="top">Species (taxon) name</td><td width="70%" align="left" valign="top"><input name="NewSpeciesName-<?= $i ?>" type="text" /></td>
						</tr>
						<tr>
						<td width="30%" align="right" valign="top">Common name</td><td width="70%" align="left" valign="top"><input name="NewSpeciesCommonName-<?= $i ?>" type="text" /></td>
						</tr>
						<tr>
						<td width="30%" align="right" valign="top">Author and date</td><td width="70%" align="left" valign="top"><input name="NewSpeciesAuthor-<?= $i ?>" type="text" /></td>
						</tr>
						<tr>
						<td width="30%" align="right" valign="top">PaleoDB taxon number</td><td width="70%" align="left" valign="top"><input name="NewSpeciesPBDBTaxonNum-<?= $i ?>" type="text" /></td>
						</tr>
						</table>

						<hr/>

						<table width="100%" border="0">
						<tr>
						<td align="right" valign="top" width="30%"><strong>location relative to node</strong></td>
						<td align="left" width="70%"><select name="RelativeLocation-<?= $i ?>" id="RelativeLocation-<?=$i?>">
							<?php
							if(mysql_num_rows($relative_location_list)==0){
								?>
									<option value="0">No relative locations in database</option>
									<?php
							} else {
								mysql_data_seek($relative_location_list,0);
								$currentRelLocation = testForProp($fossil_data, 'FossilLocationRelativeToNode', '');
								while($row=mysql_fetch_assoc($relative_location_list)) {
									$thisRelLocation = $row['RelLocationID'];
									if ($currentRelLocation == $thisRelLocation) {
										echo '<option value="'.$row['RelLocationID'].'" selected="selected">'.$row['RelLocation'].'</option>';
									} else {
										echo '<option value="'.$row['RelLocationID'].'">'.$row['RelLocation'].'</option>';
									}			
								}
							} ?>
							</select>
						</td>
						</tr>
						<tr>
						<td align="right" valign="top"><strong>minimum age (Ma)</strong></td>
						<td align="left">
						<? if ($showPreviouslyAssignedValues) { ?>
							<input type="radio" name="newOrExistingFossilMinAge-<?= $i ?>" value="ASSIGNED" id="assignedFossilMinAge-<?=$i?>" checked="checked" /> 
								<label for="assignedFossilMinAge-<?=$i?>"><b>Previously assigned</b></label>
								&nbsp;
							<select name="AssignedMinAge-<?= $i ?>" id="AssignedMinAge-<?= $i ?>">
								<? foreach($existing_links_data as $row) { ?>
									<option value="<?=$row['MinAge']?>"><?=$row['MinAge']?></option>
										<? } ?>
										</select>
										<input type="radio" name="newOrExistingFossilMinAge-<?= $i ?>" value="NEW" id="newFossilMinAge-<?=$i?>" /> 
										<label for="newFossilMinAge-<?=$i?>">or <b>new</b></label>
										&nbsp; 
							<? } ?>
							<input type="text" name="FossilMinAge-<?= $i ?>" id="FossilMinAge-<?=$i?>" size=3 value="<?= testForProp($fossil_data, 'MinAge', '') ?>"></td>
							</td>
							</tr>
							<tr>
							<td align="right" valign="top"><strong>minimum age type</strong></td>
							<td><select name="MinAgeType-<?= $i ?>" id="MinAgeType-<?=$i?>">
							<?php
							if(mysql_num_rows($agetypes_list)==0){
								?>
									<option value="0">No age types in database</option>
									<?php
							} else {
								mysql_data_seek($agetypes_list,0);
								$currentMinAgeType = testForProp($fossil_data, 'MinAgeType', '');
								while($row=mysql_fetch_assoc($agetypes_list)) {
									$thisMinAgeType = $row['AgeTypeID'];
									if ($currentMinAgeType == $thisMinAgeType) {
										echo '<option value="'.$row['AgeTypeID'].'" selected="selected">'.$row['AgeType'].'</option>';
									} else {
										echo '<option value="'.$row['AgeTypeID'].'">'.$row['AgeType'].'</option>';
									}			
								}
							} ?>
							</select>
							<br/>
							<input type="text" name="MinAgeTypeOtherDetails-<?= $i ?>" id="MinAgeTypeOtherDetails-<?=$i?>" maxlength="300" size="52"
							       style="margin-top: 2px;" value="<?= testForProp($fossil_data, 'MinAgeTypeOtherDetails', '') ?>"></td>
							</td>
</tr>
<tr>
<td align="right" valign="top" width="30%"><strong>maximum age (Ma)</strong></td>
<td align="left" width="70%">
<? if ($showPreviouslyAssignedValues) { ?>
	<input type="radio" name="newOrExistingFossilMaxAge-<?= $i ?>" value="ASSIGNED" id="assignedFossilMaxAge-<?=$i?>" checked="checked" /> 
		<label for="assignedFossilMaxAge-<?=$i?>"><b>Previously assigned</b></label>
		&nbsp;
	<select name="AssignedMaxAge-<?= $i ?>" id="AssignedMaxAge-<?= $i ?>">
		<? foreach($existing_links_data as $row) { ?>
			<option value="<?=$row['MaxAge']?>"><?=$row['MaxAge']?></option>
				<? } ?>
				</select>
				<input type="radio" name="newOrExistingFossilMaxAge-<?= $i ?>" value="NEW" id="newFossilMaxAge-<?=$i?>" /> 
				<label for="newFossilMaxAge-<?=$i?>">or <b>new</b></label>
				&nbsp; 
	<? } ?>
	<input type="text" name="FossilMaxAge-<?= $i ?>" id="FossilMaxAge-<?=$i?>" size=3 value="<?= testForProp($fossil_data, 'MaxAge', '') ?>">
	</td>
	</tr>
	<tr>
	<td align="right" valign="top"><strong>maximum age type</strong></td>
	<td><select name="MaxAgeType-<?= $i ?>" id="MaxAgeType-<?=$i?>">
	<?php
	if(mysql_num_rows($agetypes_list)==0){
		?>
			<option value="0">No age types in database</option>
			<?php
	} else {
		mysql_data_seek($agetypes_list,0);
		$currentMaxAgeType = testForProp($fossil_data, 'MaxAgeType', '');
		while($row=mysql_fetch_assoc($agetypes_list)) {
			$thisMaxAgeType = $row['AgeTypeID'];
			if ($currentMaxAgeType == $thisMaxAgeType) {
				echo '<option value="'.$row['AgeTypeID'].'" selected="selected">'.$row['AgeType'].'</option>';
			} else {
				echo '<option value="'.$row['AgeTypeID'].'">'.$row['AgeType'].'</option>';
			}			
		}
	} ?>
</select>
<br/>
<input type="text" name="MaxAgeTypeOtherDetails-<?= $i ?>" id="MaxAgeTypeOtherDetails-<?=$i?>" maxlength="300" size="52"
       style="margin-top: 2px;" value="<?= testForProp($fossil_data, 'MaxAgeTypeOtherDetails', '') ?>"></td>
</td>
</tr>
<tr>
<td align="right" valign="top">&nbsp;</td>
<td>
	<label for="TieDatesToGeoTimeScaleBoundary-<?= $i ?>">
		<input type="checkbox" id="TieDatesToGeoTimeScaleBoundary-<?= $i ?>" name="TieDatesToGeoTimeScaleBoundary-<?= $i ?>" 
			<? if (testForProp($fossil_data, 'TieDatesToGeoTimeScaleBoundary', '0') == '1') { ?>checked="checked"<? } ?>> 
		Tie date to geological time scale boundary <i>(currently unused)</i>
	</label>
</td>
</tr>

<tr>
<td align="right" valign="top"><strong>phylogenetic justification type</strong></td>
<td><select name="PhyJustType-<?= $i ?>" id="PhyJustType-<?=$i?>">
<?php
if(mysql_num_rows($phyjusttype_list)==0){
	?>
		<option value="0">No justification types in database</option>
		<?php
} else {
	mysql_data_seek($phyjusttype_list,0);
	$currentPhyloJustType = testForProp($fossil_data, 'PhyJustificationType', '');
	while($row=mysql_fetch_assoc($phyjusttype_list)) {
		$thisPhyloJustType = $row['PhyloJustID'];
		if ($currentPhyloJustType == $thisPhyloJustType) {
			echo '<option value="'.$row['PhyloJustID'].'" selected="selected">'.$row['PhyloJustType'].'</option>';
		} else {
			echo '<option value="'.$row['PhyloJustID'].'">'.$row['PhyloJustType'].'</option>';
		}			
	}
} ?>
</select>
</tr>


<tr>
<td align="right" valign="top" width="30%"><strong>phylogenetic justification</strong></td>
<td align="left" width="70%"><textarea name="PhyJustification-<?= $i ?>" id="PhyJustification-<?=$i?>" cols="50" rows="5"><?= testForProp($fossil_data, 'PhyJustification', '') ?></textarea></td>
                    </tr>
    </table>
                    
    <hr/>

    <!-- for n phylogeny publications, let's use a list with checkboxes, and a widget to add more -->
    <p style="overflow: hidden;">
        <label>Choose one or more <b>phylogeny publications</b></label>
        <a href="/protected/manage_publications.php" target="_new" style="float: right; font-size: 80%;">Show all publications in a new window</a>
    </p>
    <table width="100%"><tr><td class="phylo-pub-info-list">
<? if (is_array($phylo_pub_data)) { 
        $fossilNum = $i;
        $phylopubNum = 0;
        foreach ($phylo_pub_data as $ppub) { 
                $phylopubNum++;
                // generate inline HTML block with its values
                phylo_publication_info_block(
                    $fossilNum, 
                    $phylopubNum, 
                    testForProp($ppub, 'PublicationID', ''), 
                    testForProp($ppub, 'ShortName', ''), 
                    testForProp($ppub, 'FullReference', '&nbsp;'),
                    testForProp($ppub, 'DOI', '&nbsp;')
                );
        }
  } ?>
    </td></tr></table>

    <table id="enter-new-phylo-pub-<?=$i?>" class="add-form" width="100%" border="0" style="display: none;">
            <tr>
              <td align="right" valign="top" width="30%"><strong>short form (author, date)</strong></td>
              <td align="left" width="70%"><input type="text" name="ShortName" size="10" style="width: 50%;"></td>
            </tr>
            <tr>
              <td align="right" valign="top" width="30%"><strong>full citation</strong></td>
              <td align="left" width="70%"><input type="text" name="FullReference" style="width: 95%;"></td>
            </tr>
            <tr>
              <td align="right" valign="top" width="30%"><strong>doi (or other url)</strong></td>
              <td align="left" width="70%"><input type="text" name="DOI" size="10" style="width: 50%;"></td>
            </tr>
            <tr>
              <td width="30%">&nbsp;</td>
              <td align="left" width="70%">
                <input type="button" style="font-size: 0.8em;" value="cancel" 
                       onclick="cancelNewPhyloPub(this); return false;"/>
                <input type="button" style="font-size: 0.8em;" value="OK" 
                       onclick="acceptNewPhyloPub(this); return false;"/>
              </td>
            </tr>
    </table>
    <div style="float: right; text-align: right; padding: 6px 0;">
        <input type="button" id="add-previous-phylo-pub" style="font-size: 0.8em;" value="add an existing publication" 
               onclick="pickExistingPublicationAsPhyloPublication(this); return false;"/>
        <input type="button" id="add-fossil-pub-as-phylo-pub" style="font-size: 0.8em; margin-left: 12px;" value="copy fossil publication above"
               onclick="reuseFossilPublicationAsPhyloPublication(this); return false;"/>
        <input type="button" id="add-existing-phylo-pub" style="font-size: 0.8em; margin-left: 12px;" value="add a new publication to the database" 
               onclick="addNewPhyloPublicationToDatabase(this); return false;"/>
    </div>
</div><!-- END of #fossil-properties-{#} -->
  </div><!-- END of individual fossil panel -->

