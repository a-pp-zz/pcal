<?php
namespace AppZz\Http;
use AppZz\Http\CurlClient;
use AppZz\Helpers\Arr;
use AppZz\Helpers\HtmlDomParser;
use Eluceo\iCal\Component\Event;
use Eluceo\iCal\Component\Calendar;
use DateTime;

/**
 * Производственный календарь
 *
 * @author CoolSwitcher
 * @copyright yoip.ru
 * @
 */
class pCal {

	private $_year = 2016;
	private $_dom;
	private $_url;
	private $_hash;
	private $_headers;

	private $_months = [
		1  => 'Января',
		2  => 'Февраля',
		3  => 'Марта',
		4  => 'Апреля',
		5  => 'Мая',
		6  => 'Июня',
		7  => 'Июля',
		8  => 'Августа',
		9  => 'Сентября',
		10 => 'Октября',
		11 => 'Ноября',
		12 => 'Декабря',
	];

	private $_events_bad_words = [
		'Это выходной день',
		'Это праздничный день',
		'Это рабочий день'
	];

	const CALENDAR_URL = 'http://calendar.yoip.ru/work/%04d-proizvodstvennyj-calendar.html';

	public function __construct ($year = 2016)
	{
		$this->_year = $year;
		$this->_url = sprintf (pCal::CALENDAR_URL, $this->_year);

		$content = $this->_get_content();

		if ($content) {
			$this->_hash = sha1 ($content);
			$this->_dom = HtmlDomParser::str_get_html($content);
		}
	}

	public function get_events ()
	{
		if ( ! $this->_dom) {
			return FALSE;
		}

		$calend = $this->_parse_calendar();
		$main   = $this->_parse_main_events();
		$long   = $this->_parse_long_events();

		$diff1 = array_diff_key($main, $long);
		$diff2 = array_diff_key($calend, $long);
		$full  = array_merge ($long, $diff1, $diff2, $main);
		$ret = [];

		foreach ($full as $k=>$v) {
			$description = Arr::get ($calend, $k, '');

			if ($description == $v) {
				$description = '';
			}

			$ret[] = [
				'date'=>$k,
				'title'=>$v,
				'description'=>$description
			];
		}

		usort ($ret, function ($b, $a) {
			return (strtotime ($b['date']) > strtotime($a['date']));
		});

		return $ret;
	}

	public function render ($events, $file = FALSE)
	{
		$prodid = 'pCal-' . $this->_year;
		$vCalendar = new Calendar($prodid);

		foreach ($events as $event) {
			$date = Arr::get($event, 'date');
			$description = Arr::get($event, 'description');
			$title = Arr::get($event, 'title');

			if ( !$title OR !$date) {
				continue;
			}

			$vEvent = new Event();
			$vEvent->setDtStart(new DateTime($date));
			$vEvent->setDtEnd(new DateTime($date));
			$vEvent->setNoTime(true);
			$vEvent->setSummary($title);
			if ( !empty ($description))
				$vEvent->setDescription($description);
			$vCalendar->addComponent($vEvent);
		}

		$output = $vCalendar->render();

		if ($file) {
			file_put_contents($file, $output);
			return TRUE;
		} else {
			$_date = Arr::get($this->_headers, 'Date');
			$_expires = Arr::get($this->_headers, 'Expires');
			header('Content-Type: text/calendar; charset=utf-8');
			header("ETag: \"{$this->_hash}\"", TRUE);
			header ('Cache-Control: max-age=0');

			if ($_date) {
				header ('Date: ' . $_date);
			}

			if ($_expires) {
				header ('Expires: ' . $_expires);
			}

			header('Content-Disposition: attachment; filename="'.strtolower($prodid).'.ics"');
			echo $output;
			exit;
		}
	}

	private function _get_content ()
	{
		$request = CurlClient::get($this->_url);
		$request->browser('chrome', 'mac');
		$request->accept('html', 'gzip');
		$response = $request->send();

		if ($response->get_status() === 200) {
			$this->_headers = $response->get_headers()->asArray();
			return $response->get_body();
		}

		return FALSE;
	}

	private function _detect_date ($text)
	{
		$months = '(' . implode ('|', $this->_months) . ')';
		$regex  = '#(\d{1,2})\s'.$months.'\s?(\d{4})?#iu';

		if (preg_match ($regex, $text, $matches)) {
			$month = trim ($matches[2]);
			$month = array_search($month, $this->_months);

			if ( ! $month) {
				return FALSE;
			}

			if ( ! isset ($matches[3])) {
				$matches[3] = $this->_year;
			}

			return sprintf ('%04d-%02d-%02d', $matches[3], $month, $matches[1]);
		}

		return FALSE;
	}

	private function _detect_date_span ($text)
	{
		$spans = explode ('/', $text);
		$start = Arr::get($spans, 0);
		$end   = Arr::get($spans, 1);

		if (!$start OR !$end) {
			return FALSE;
		}

		$start = $this->_detect_date(trim($start));
		$end = $this->_detect_date(trim($end));

		$m1 = (int) date ('n', strtotime($start));
		$m2 = (int) date ('n', strtotime($end));

		if (($m1 === 12) AND ($m2 === 1)) {
			$end = date ('Y-m-d', strtotime($end . ' +1 year'));
		}

		$current = $start;
		$dates = [$current];

		while (strtotime($current) < strtotime ($end)) {
			$current = date ('Y-m-d', strtotime ($current . ' +1 day'));
			$dates[] = $current;
		}

		return $dates;
	}

	private function _format_date ($text)
	{
		$text = trim ($text);
		$regex  = '#(\d{4})\-(\d{2})\-(\d{2})#iu';

		if (preg_match ($regex, $text, $matches)) {
			$month = sprintf ('%d', trim ($matches[2]));
			$month = Arr::get($this->_months, $month);

			if ( ! $month) {
				return $text;
			}

			$date = sprintf('%d %s %04d', $matches[3], $month, $matches[1]);
			return str_replace($matches[1] . '-' . $matches[2] . '-' . $matches[3], $date, $text);
		}

		return $text;
	}

	public function _parse_calendar ()
	{
		$events = [];
		$elms = $this->_dom->find('div.col-6 table.table td.tt-hd');

		foreach ($elms as $elm) {
			$title = $elm->getAttribute('title');
			$title = html_entity_decode($title);
			$parts = explode ('.', $title);
			$parts = array_map('trim', $parts);

			if ($parts) {
				$event_title = $this->_format_date($parts[1]);

				if (in_array($event_title, $this->_events_bad_words)) {
					continue;
				}

				$date = $this->_detect_date($parts[0]);

				if ( ! $date) {
					continue;
				}

				$events[$date] = $event_title;
			}
		}

		return $events;
	}

	public function _parse_main_events ()
	{
		$events = [];
		$elms = $this->_dom->find('div.col-8 table.table-condensed td.danger');

		foreach ($elms as $elm) {
			$date = $elm->plaintext;
			$date = html_entity_decode($date);
			$title = $elm->next_sibling()->plaintext;
			$date = $this->_detect_date($date);

			if ( ! $date) {
				continue;
			}

			$events[$date] = $title;
		}

		return $events;
	}

	public function _parse_long_events ()
	{
		$events = [];
		$elms = $this->_dom->find('div.col-10 table.table-condensed td');

		foreach ($elms as $elm) {
			$date = $elm->plaintext;
			$date = html_entity_decode($date);
			$dates = $this->_detect_date_span($date);

			if ($dates) {
				$title = $elm->next_sibling()->next_sibling()->plaintext;

				foreach ($dates as $date) {
					$events[$date] = $title;
				}
			}
		}

		return $events;
	}
}
