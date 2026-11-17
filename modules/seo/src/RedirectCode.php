<?php

namespace BarrioSuite\Seo;

// Stessi valori del CHECK br_code_chk: lo verifica SchemaTest.
enum RedirectCode: int {
	case MovedPermanently  = 301;
	case Found             = 302;
	case TemporaryRedirect = 307;
	case PermanentRedirect = 308;
	case Gone              = 410;
}
