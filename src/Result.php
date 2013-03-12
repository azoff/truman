<?
class TrumanResult extends SimpleXMLElement {

	const TAG_PREFIX = 'RESULT_';

	public function __toString() {
		$data = $this->data();
		$buck = ($data && isset($data->buck)) ? $data->buck : '(empty)';
		$bool = $this ? 'passed' : 'failed';
		return __CLASS__."<{$buck} => {$bool}>";
	}

	public function data() {
		if (preg_match("#(RESULT_\w+)#", $this->asXML(), $matches))
			return self::decode($matches[1]);
		return null;
	}

	public function asXML($filename = null) {
		$xml   = parent::asXML();
		$parts = explode("\n", $xml);
		$parts = array_filter($parts);
		$xml   = array_pop($parts);
		return "{$xml}\n";
	}

	public static function decode($tag_safe) {
		$tag_safe   = explode(self::TAG_PREFIX, $tag_safe);
		$xml_safe   = array_pop($tag_safe);
		$xml_safe   = str_replace('_p', '+', $xml_safe);
		$encoded    = str_replace('_e', '=', $xml_safe);
		$serialized = base64_decode($encoded);
		$data       = unserialize($serialized);
		return $data;
	}

	public static function encode($data) {
		$serialized = serialize($data);
		$encoded    = base64_encode($serialized);
		$xml_safe   = str_replace('=', '_e', $encoded);
		$xml_safe   = str_replace('+', '_p', $xml_safe);
		$tag_safe   = self::TAG_PREFIX . $xml_safe;
		return $tag_safe;
	}

	public static function newInstance($truthy = true, $data = null) {
		$tagname  = self::encode($data);
		$instance = new self("<$tagname/>");
		if ($truthy) $instance->addAttribute('truthy', 1);
		return $instance;
	}

}
