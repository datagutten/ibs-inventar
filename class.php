<?php
require_once 'vendor/autoload.php';
class inventar
{
	public $db;
	public $refs=array('ref_kat'=>'kategorier','ref_prs'=>'personer','ref_avd'=>'avdelinger','ref_rom'=>'rom','ref_status'=>'status','ref_lev'=>'leverandorer');
	public $basequery="SELECT * FROM inventar, inventarkat WHERE inventar.id=inventarkat.ref_inv";
	public $personer=false;
	public $error;
	public $st_finnperson;
	public $st_finnperson_skole;
	function __construct()
	{
		error_reporting(E_ALL);
		ini_set('display_errors',1);
		$this->db=new pdo_helper;
		$this->db->connect_db_config('config_db_inventar.php');
		$q="FROM inventar,inventarkat,personer,avdelinger WHERE inventar.ref_prs=personer.id AND inventar.id=inventarkat.ref_inv AND personer.ref_avd=avdelinger.id";
	}
	function query($q,$fetch='all')
	{
		return $this->db->query($q,$fetch);
	}
	function execute($st,$parameters,$fetch=false)
	{
		return $this->db->execute($st,$parameters,$fetch);
	}
	function history($id,$text)
	{
		$sqlDateTime = date('Y-m-d H:i:s'); 
		$this->db->query($q="INSERT INTO history (id,hist_date,hist_user,hist_action,module_src,module_dest,message) VALUES ('$id','$sqlDateTime','php',2,1,1,'$text')") or die('Feil');
	}
	function finnmaskin()
	{
		$query=$this->db->prepare("SELECT personer.navn AS navn,inventar.ref_status AS status,inventarkat.felt3 AS maskin,inventar.id AS id FROM inventar,inventarkat,personer WHERE inventar.ref_prs=personer.id AND inventar.id=inventarkat.ref_inv AND inventarkat.felt3 LIKE :maskin");
	}
	public function deviceinfo($id) //Get info about a device
	{
		$st_device=$this->db->prepare($this->basequery." AND inventar.id=?");
		$st_device->execute(array($id));
		$devices=$st_device->fetchAll(PDO::FETCH_ASSOC);
		return $devices;
	}
	function finnperson($navn,$mode='skole',$status=false,$kategori=false)
	{
		$statusquery='';
		$navn=$this->db->quote($navn);
		if($mode=='skole')
			$fields="inventar.ref_status AS status,inventarkat.felt3 AS maskin,inventar.id AS id";
		else
			$fields='inventar.*,inventarkat.*,personer.navn as navn';
		if($status===false)
			$statusquery=" AND ref_status=1";
		elseif($status=='%')
			$statusquery='';
		elseif(!is_numeric($status))
			die("Ugyldig status $status\n");

		if($kategori!==false)
			$statusquery.=" AND ref_kat='$kategori'";
		$result=$this->db->query($q="SELECT $fields FROM inventar,inventarkat,personer WHERE inventar.ref_prs=personer.id AND inventar.id=inventarkat.ref_inv AND personer.navn=$navn".$statusquery);
		//echo $q."\n";
		$maskiner=$result->fetchall(PDO::FETCH_ASSOC);
		return $maskiner;
	}
	function finn_utstyr($options=array('person'=>false,'status'=>false,'kategori'=>false),$fetch=false)
	{
		$q=$this->basequery;
		if(!empty($options['person']))
			$q=sprintf('%s AND ref_prs=%s',$q,$this->db->quote($options['person']));
		if(!empty($options['status']) && is_numeric($options['status']))
			$q=sprintf('%s AND ref_status=%d',$q,$options['status']);
		if(!empty($options['kategori']))
			$q=sprintf('%s AND ref_kat=%s',$q,$this->db->quote($options['kategori']));
		return $this->db->query($q,$fetch);
	}
	function pc_bruker_skole($navn)
	{
		if(empty($this->st_finnperson_skole))
			$this->st_finnperson_skole=$this->db->prepare('
			SELECT inventar.ref_status AS status,inventarkat.felt3 AS maskin,inventar.id AS id 
			FROM inventar,inventarkat,personer 
			WHERE inventar.ref_prs=personer.id 
			AND inventar.id=inventarkat.ref_inv 
			AND personer.navn=? 
			AND ref_status=1
			AND ref_kat=\'PC\'');
		return $this->db->execute($this->st_finnperson_skole,array($navn),'all');
	}
	public function skolemaskiner($school,$status=false,$kategori=false) //Hent alle maskiner på en skole
	{
		$st=$this->db->prepare("SELECT inventar.ref_status AS status,inventarkat.felt3 AS maskin,inventar.id AS id FROM inventar,inventarkat,personer WHERE inventar.ref_prs=personer.id AND inventar.id=inventarkat.ref_inv AND inventarkat.felt3 LIKE ?");
		if($st->execute(array($school))===false)
		{
			$errorinfo=$st->errorInfo();
			trigger_error(htmlentities($errorinfo[2]),E_USER_ERROR);
		}
		$result=$st->fetchAll(PDO::FETCH_ASSOC);
		return $result;
	}
	function finnhistorie($search)
	{
		$search=$this->db->quote("%$search%");
		$st=$this->db->prepare("SELECT * FROM history WHERE message LIKE $search");
		$history=$st->fetchAll(PDO::FETCH_ASSOC);
		return $history;
	}
	function personliste()
	{
		foreach($this->db->query('SELECT id,navn FROM personer') as $row)
		{
			$personer[$row['id']]=$row['navn'];
		}
		return $personer;
	}
	function brukernavn($navn)
	{
		if($this->personer===false)
			$this->personer=$this->personliste();
		return $brukernavn=array_search($navn,$this->personer);
	}
	function visutstyr($devices,$visfelter,$kategori=false)
	{
		if($kategori===false)
		{
			if(count(array_unique(array_column($devices,'ref_kat')))==1) //Hvis alt utstyret har samme kategori kan spesifikke feltnavn vises
				$kategori=$devices[0]['ref_kat'];
			else
				$kategori=false;
		}

		$output='<table border="1">'."\n";
		$output.=$this->feltheader($visfelter,$kategori);
		foreach ($devices as $device)
		{
			$device=$this->resolve_refs($device,true);
			$output.="<tr>\n";
			foreach($visfelter as $felt)
			{
				$output.="\t<td>{$device[$felt]}</td>\n"; 
			}

			$output.="</tr>\n";
		}
		$output.="</table>\n";
		return $output;
	}
	function feltnavn($kategori=false)
	{
		//Hent feltnavn
		foreach($this->db->query("SELECT keyvalue,textvalue FROM settings WHERE sectionvalue='FIELDNAME' AND keyvalue LIKE 'INVENTAR%'") as $row) //Hent feltnavn
		{
			//$felter[]=$row;
			//$feltkeys[]=substr($row[0],9); //Hent keys for feltnavn (fjern de første 9 tegnene)
			//$feltnavn[]=$row[1]; //Hent feltnavn
			$fields[substr($row[0],9)]=$row[1];
		}
		if($kategori!==false) //Hent korrekte navn for spesialfelter
		{
			$st=$this->db->prepare($q="SELECT caption0 AS felt0,caption1 AS felt1,caption2 AS felt2,caption3 AS felt3,caption4 AS felt4,caption5 AS felt5,caption6 AS felt6,caption7 AS felt7,caption8 AS felt8,caption9 AS felt9 FROM kategorier WHERE id=?");
			$st->execute(array($kategori));
			$captions=$st->fetch(PDO::FETCH_ASSOC);
			$fields=array_merge($fields,$captions);
		}
		else //Lag generelle navn for spesialfelter
		{
			for ($i=0; $i<10; $i++) //I databasen er spesialfeltene nummerert fra 0. Legg på 1 på hvert nummer når de skal vises
			{
				$labelnum=$i+1;
				$fields['felt'.$i]='Felt '.$labelnum;
			}
		}

		return $fields;
	}
	function feltheader($feltliste,$kategori=false) //Første parameter skal være et array med feltnavn fra databasen
	{
		$feltnavn=$this->feltnavn($kategori); //Hent navn på feltene
		$output="<tr>\n";
		foreach ($feltliste as $felt) //Gå gjennom listen over felter som skal vises
		{
			if(!isset($feltnavn[$felt]))
				$feltnavn[$felt]=$felt;
			$output.="\t<th title=\"$feltnavn[$felt]\">$feltnavn[$felt]</th>\n"; //Vis feltnavnene. Fullt navn vises som tooltip
		}
		$output.="</tr>\n";
		return $output;
	}
	
	function lagSelectarray($display_key,$value_key,$name, $preselect = NULL) {
			echo '<select name="'.$name.'" id="'.$name.'">';

			for ($i=0; $i<=count($value_key)-1; $i++) {
					$value = $value_key[$i];
					$display = $display_key[$i];
					$selected = ((!empty($preselect)) && ($preselect == $value)) ? ' selected="selected"' : '';
					echo '<option value="'.$value.'"'.$selected.'>'.$display.'</option>'."\n";
			}
			echo '</select>';
	}
	function getdata($table)
	{
		if(array_search($table,$this->refs)===false)
			return false;
		if($table=='status')
			$q="SELECT id,tekst AS navn FROM typer WHERE tabell=2";
		else
			$q="SELECT id,navn FROM $table";
		foreach($this->db->query($q) as $row)
		{
			$return[$row['id']]=$row['navn'];
		}
		return $return;
	}
	function resolverefs(&$post) //Erstatt verdi med id
	{
		$post=$this->resolve_refs($post,false);
	}
	
/*	function resolverefs(&$post) //Erstatt verdi med id
	{
		foreach(array_filter($post) as $key=>$value)
		{
			if(substr($key,0,3)!='ref')
				continue;
			$value=$this->db->quote($value);

			$table=$this->refs[$key];
			//var_Dump($table);
			
			if($table=='status')
				$q="SELECT id FROM typer WHERE tabell=2 AND tekst=$value";
			else
				$q="SELECT id FROM $table WHERE navn=$value";

			$post[$key]=$this->db->query($q)->fetchColumn();
			echo $q."\n";
		}

	}*/

	function resolve_refs($device,$id_to_value=false) //Erstatt id med verdi eller motsatt
	{
		foreach(array_filter($device) as $key=>$value) //Hopp over tomme felter
		{
			if(substr($key,0,3)!='ref' || !isset($this->refs[$key]))
				continue;

			$table=$this->refs[$key];

			if($id_to_value) //id til verdi
			{
				if($table=='status')
					$st=$this->db->prepare("SELECT tekst FROM typer WHERE tabell=2 AND id=?");
				else
					$st=$this->db->prepare("SELECT navn FROM $table WHERE id=?");
			}
			else //verdi til id
			{
				if($table=='status')
					$st=$this->db->prepare("SELECT id FROM typer WHERE tabell=2 AND tekst=?");
				else
					$st=$this->db->prepare("SELECT id FROM $table WHERE navn=?");
			}

			$st->execute(array($value));
			$device[$key]=$st->fetchColumn();
		}
		return $device;
	}
	function bytt_id($oldid,$newid)
	{
		$this->query("UPDATE inventar SET id='$newid' WHERE id='$oldid'");
		$this->query("UPDATE inventarkat SET ref_inv='$newid' WHERE ref_inv='$oldid'");
		$this->query("UPDATE history SET id='$newid' WHERE id='$oldid'");
		$sqlDateTime = date('Y-m-d H:i:s'); 
		$this->query($q="INSERT INTO history (id,hist_date,hist_user,hist_action,module_src,module_dest,message) VALUES ('$newid','$sqlDateTime','php',2,1,1,'Byttet id fra $oldid')");
	}
	function free_id($series=3)
	{
		$q=sprintf('SELECT MAX (id)+1 FROM inventar WHERE id>=%1$d0000 AND id<=%1$d9999',$series);
		return $this->db->query($q,'column');
	}
}
