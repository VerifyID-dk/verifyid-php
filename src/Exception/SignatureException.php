<?php

declare(strict_types=1);

namespace VerifyID\Exception;

/** En webhook kunne ikke verificeres: header mangler, tiden er for gammel, eller signaturen passer ikke. */
final class SignatureException extends VerifyIDException
{
}
