<?php

namespace BarrioSuite\Forms\Model;

// Stessi valori del CHECK bf_status_chk: lo verifica SchemaTest.
enum FormStatus: string {
	case Active = 'active';
	case Closed = 'closed';
	case Trash  = 'trash';
}
