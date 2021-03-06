<?php

	defined('_DIRECT_ACCESS_CHECK') or exit();

	function encrypt_decrypt($encrypt, $key, $iv, $string) {
		if( $encrypt == true) {
			return openssl_encrypt($string, 'AES-256-CBC', $key, 0, $iv);
		} else {
			return openssl_decrypt($string, 'AES-256-CBC', $key, 0, $iv);
		}
	}

	function connect() {
		$dbName = "secrets.sqlite";
		$results = glob("*--{$dbName}");

		if ( count($results) != 1 ) {
			$prefix = substr(str_shuffle(implode(array_merge(range('A','Z'), range('a','z'), range(0,9)))), 0, 20);
			$dbName = "{$prefix}--{$dbName}";
		} else {
			$dbName = $results[0];
		}

		$db = new PDO("sqlite:{$dbName}");
		$db->exec('CREATE TABLE IF NOT EXISTS "secrets" ("id" TEXT PRIMARY KEY, "iv" TEXT, "hash" TEXT, "secret" TEXT)');
		return $db;
	}

	function getStaticKey() {
		$keyName = "aes-static.key";
		$results = glob("*--{$keyName}");
		$staticKey = null;

		if ( count($results) != 1 ) {
			#static key needs to be created
			$prefix = substr(str_shuffle(implode(array_merge(range('A','Z'), range('a','z'), range(0,9)))), 0, 20);
			$keyName = "{$prefix}--{$keyName}";
			$staticKey = random_str(32);

			if ($fp = fopen($keyName, "w")) {
				fwrite($fp, $staticKey);
				fclose($fp);
			} else {
				throw new Exception('Failed to write static key to disk!');
			}
		} else {
			#read static key from disk
			$keyName = $results[0];
			if ( ($fp = fopen($keyName, "rb")) !== false ) {
				$staticKey = stream_get_contents($fp);
				fclose($fp);
			} else {
				throw new Exception('Unable to read static key from disk!');
			}
		}

		if ( strlen($staticKey) >= 32 ) {
			return $staticKey;
		} else {
			throw new Exception('Bad static key length!');
		}
	}

	function writeSecret($db, $id, $iv, $hash, $secret) {
		$statement = $db->prepare('INSERT INTO "secrets" ("id", "iv", "hash", "secret") VALUES (:id, :iv, :hash, :secret)');
		$statement->bindValue(':id', $id);
		$statement->bindValue(':iv', $iv);
		$statement->bindValue(':hash', $hash);
		$statement->bindValue(':secret', $secret);
		$statement->execute();
	}

	function readSecret($db, $id) {
		$statement = $db->prepare('SELECT * FROM "secrets" WHERE id = :id LIMIT 1');
		$statement->bindValue(':id', $id);
		$statement->execute();
		$result = $statement->fetch(PDO::FETCH_ASSOC);
		return $result;
	}

	function deleteSecret($db, $id) {
		$statement = $db->prepare('DELETE FROM "secrets" WHERE id = :id');
		$statement->bindValue(':id', $id);
		$statement->execute();

		$verify = $db->prepare('SELECT COUNT(*) FROM "secrets" WHERE id = :id');
		$verify->bindValue(':id', $id);
		$verify->execute();
		return ( $verify->fetchColumn() == 0 );
	}

	function random_str($byteLen) {
		for ($i = -1; $i <= $byteLen; $i++) {
			$bytes = openssl_random_pseudo_bytes($i, $cstrong);
		}
		return $bytes;
	}

	function base64_encode_mod($input) {
		return strtr(base64_encode($input), '+/=', '-_#');
	}

	function base64_decode_mod($input) {
		return base64_decode(strtr($input, '-_#', '+/='));
	}

	function store_secret($secret) {
		#connect to sqlite db
		$db = connect();

		#generate random id, iv, key
		$id = random_str(8);
		$iv = random_str(16);
		$key = random_str(32);

		#generate k value for url (id + key)
		$k = base64_encode_mod($id . $key);

		#generate hash of id + key
		$hash = password_hash($id . $key, PASSWORD_BCRYPT);

		#encrypt text with key and then static key
		$secret = encrypt_decrypt(true, $key, $iv, $secret);
		$secret = encrypt_decrypt(true, getStaticKey(), $iv, $secret);

		#base64 encode the id, iv, and secret for db storage
		$id = base64_encode_mod($id);
		$iv = base64_encode_mod($iv);
		$secret = base64_encode_mod($secret);

		#write secret_hash, iv, and secret to database
		writeSecret($db, $id, $iv, $hash, $secret);

		#close db
		$db = null;

		#return base64(id + key)
		return $k;
	}

	function retrieve_secret($k) {

		#connect to sqlite db
		$db = connect();

		#validate length of k - must be 40 chars (id = 8, key = 32)
		if ( strlen(base64_decode_mod($k)) != 40 ) {
			throw new Exception('This secret can not be found!');
		}

		#extract key and id from k. base64 encode id
		$k = base64_decode_mod($k);
		$key = substr($k, -32);
		$id = substr($k, 0, 8);
		$idBase64 = base64_encode_mod($id);

		#look up secret by id
		$secretQuery = readSecret($db, $idBase64);

		#throw exception if query failed
		if ( ! $secretQuery ) {
			throw new Exception('This secret can not be found!');
		}

		$iv = base64_decode_mod($secretQuery['iv']);
		$hash = $secretQuery['hash'];
		$secret = base64_decode_mod($secretQuery['secret']);

		#verify hash from DB equals hash of id + key from URL
		if ( ! password_verify($id . $key, $hash) ) {
			throw new Exception('This secret can not be found!');
		}

		#decrypt secret with the static key, and then with url key
		$secret = encrypt_decrypt(false, getStaticKey(), $iv, $secret);
		$secret = encrypt_decrypt(false, $key, $iv, $secret);

		#delete secret and verify it's gone
		if ( ! deleteSecret($db, $idBase64) ) {
			throw new Exception('This secret can not be found!');
		}

		#close db
		$db = null;

		#return decrypted text
		return $secret;
	}

?>
