<?php

/*
|--------------------------------------------------------------------------
| Locations
|--------------------------------------------------------------------------
|
| Countries, their states, and the larger cities of each. Not exhaustive - the
| city lists cover the main population centres, not every town.
|
| "Unknown" leads every state and city list on purpose. An applicant who does
| not know, or whose town is not listed, must still be able to finish a form,
| and "Unknown" recorded honestly is worth more than a wrong city picked to get
| past a required field. Its state code "UNK" is deliberately not a real ISO
| code, so it matches no lender state restriction - see
| LenderVisibility::getStateCode().
|
*/

return [
    [
        "name" => "United States",
        "code" => "USA",
        "currency" => "USD",
        "phone_code" => "+1",
        "phone_national_length" => 10,
        "flag" => "🇺🇸.svg",
        "states" => [
            [
                "name" => "Unknown",
                "code" => "UNK",
                "cities" => ["Unknown"]
            ],
            [
                "name" => "Alabama",
                "code" => "AL",
                "cities" => ["Unknown", "Birmingham", "Montgomery", "Mobile", "Huntsville", "Tuscaloosa", "Hoover", "Dothan", "Auburn", "Decatur", "Madison"]
            ],
            [
                "name" => "Alaska",
                "code" => "AK",
                "cities" => ["Unknown", "Anchorage", "Fairbanks", "Juneau", "Sitka", "Ketchikan", "Wasilla", "Kenai", "Kodiak", "Bethel", "Palmer"]
            ],
            [
                "name" => "Arizona",
                "code" => "AZ",
                "cities" => ["Unknown", "Phoenix", "Tucson", "Mesa", "Chandler", "Scottsdale", "Glendale", "Gilbert", "Tempe", "Peoria", "Surprise"]
            ],
            [
                "name" => "Arkansas",
                "code" => "AR",
                "cities" => ["Unknown", "Little Rock", "Fort Smith", "Fayetteville", "Springdale", "Jonesboro", "North Little Rock", "Conway", "Rogers", "Pine Bluff", "Bentonville"]
            ],
            [
                "name" => "California",
                "code" => "CA",
                "cities" => ["Unknown", "Los Angeles", "San Diego", "San Jose", "San Francisco", "Fresno", "Sacramento", "Long Beach", "Oakland", "Bakersfield", "Anaheim", "Santa Ana", "Riverside", "Stockton", "Irvine", "Chula Vista"]
            ],
            [
                "name" => "Colorado",
                "code" => "CO",
                "cities" => ["Unknown", "Denver", "Colorado Springs", "Aurora", "Fort Collins", "Lakewood", "Thornton", "Arvada", "Westminster", "Pueblo", "Centennial"]
            ],
            [
                "name" => "Connecticut",
                "code" => "CT",
                "cities" => ["Unknown", "Bridgeport", "New Haven", "Stamford", "Hartford", "Waterbury", "Norwalk", "Danbury", "New Britain", "Bristol", "Meriden"]
            ],
            [
                "name" => "Delaware",
                "code" => "DE",
                "cities" => ["Unknown", "Wilmington", "Dover", "Newark", "Middletown", "Smyrna", "Milford", "Seaford", "Georgetown", "Elsmere", "New Castle"]
            ],
            [
                "name" => "District of Columbia",
                "code" => "DC",
                "cities" => ["Unknown", "Washington"]
            ],
            [
                "name" => "Florida",
                "code" => "FL",
                "cities" => ["Unknown", "Jacksonville", "Miami", "Tampa", "Orlando", "St. Petersburg", "Hialeah", "Tallahassee", "Fort Lauderdale", "Port St. Lucie", "Cape Coral", "Pembroke Pines", "Hollywood", "Miramar", "Gainesville", "Coral Springs"]
            ],
            [
                "name" => "Georgia",
                "code" => "GA",
                "cities" => ["Unknown", "Atlanta", "Augusta", "Columbus", "Macon", "Savannah", "Athens", "Sandy Springs", "Roswell", "Johns Creek", "Albany"]
            ],
            [
                "name" => "Hawaii",
                "code" => "HI",
                "cities" => ["Unknown", "Honolulu", "Pearl City", "Hilo", "Kailua", "Waipahu", "Kaneohe", "Mililani", "Kahului", "Ewa Beach", "Kapolei"]
            ],
            [
                "name" => "Idaho",
                "code" => "ID",
                "cities" => ["Unknown", "Boise", "Meridian", "Nampa", "Idaho Falls", "Pocatello", "Caldwell", "Coeur d'Alene", "Twin Falls", "Lewiston", "Post Falls"]
            ],
            [
                "name" => "Illinois",
                "code" => "IL",
                "cities" => ["Unknown", "Chicago", "Aurora", "Naperville", "Joliet", "Rockford", "Springfield", "Elgin", "Peoria", "Champaign", "Waukegan"]
            ],
            [
                "name" => "Indiana",
                "code" => "IN",
                "cities" => ["Unknown", "Indianapolis", "Fort Wayne", "Evansville", "South Bend", "Carmel", "Bloomington", "Fishers", "Hammond", "Gary", "Muncie"]
            ],
            [
                "name" => "Iowa",
                "code" => "IA",
                "cities" => ["Unknown", "Des Moines", "Cedar Rapids", "Davenport", "Sioux City", "Iowa City", "Waterloo", "Council Bluffs", "Ames", "West Des Moines", "Dubuque"]
            ],
            [
                "name" => "Kansas",
                "code" => "KS",
                "cities" => ["Unknown", "Wichita", "Overland Park", "Kansas City", "Olathe", "Topeka", "Lawrence", "Shawnee", "Manhattan", "Lenexa", "Salina"]
            ],
            [
                "name" => "Kentucky",
                "code" => "KY",
                "cities" => ["Unknown", "Louisville", "Lexington", "Bowling Green", "Owensboro", "Covington", "Richmond", "Georgetown", "Florence", "Hopkinsville", "Nicholasville"]
            ],
            [
                "name" => "Louisiana",
                "code" => "LA",
                "cities" => ["Unknown", "New Orleans", "Baton Rouge", "Shreveport", "Lafayette", "Lake Charles", "Kenner", "Bossier City", "Monroe", "Alexandria", "Houma"]
            ],
            [
                "name" => "Maine",
                "code" => "ME",
                "cities" => ["Unknown", "Portland", "Lewiston", "Bangor", "South Portland", "Auburn", "Biddeford", "Sanford", "Saco", "Augusta", "Westbrook"]
            ],
            [
                "name" => "Maryland",
                "code" => "MD",
                "cities" => ["Unknown", "Baltimore", "Columbia", "Germantown", "Silver Spring", "Waldorf", "Glen Burnie", "Ellicott City", "Frederick", "Rockville", "Gaithersburg"]
            ],
            [
                "name" => "Massachusetts",
                "code" => "MA",
                "cities" => ["Unknown", "Boston", "Worcester", "Springfield", "Cambridge", "Lowell", "Brockton", "New Bedford", "Quincy", "Lynn", "Fall River"]
            ],
            [
                "name" => "Michigan",
                "code" => "MI",
                "cities" => ["Unknown", "Detroit", "Grand Rapids", "Warren", "Sterling Heights", "Ann Arbor", "Lansing", "Flint", "Dearborn", "Livonia", "Clinton Township"]
            ],
            [
                "name" => "Minnesota",
                "code" => "MN",
                "cities" => ["Unknown", "Minneapolis", "St. Paul", "Rochester", "Duluth", "Bloomington", "Brooklyn Park", "Plymouth", "St. Cloud", "Eagan", "Woodbury"]
            ],
            [
                "name" => "Mississippi",
                "code" => "MS",
                "cities" => ["Unknown", "Jackson", "Gulfport", "Southaven", "Hattiesburg", "Biloxi", "Meridian", "Tupelo", "Greenville", "Olive Branch", "Horn Lake"]
            ],
            [
                "name" => "Missouri",
                "code" => "MO",
                "cities" => ["Unknown", "Kansas City", "St. Louis", "Springfield", "Columbia", "Independence", "Lee's Summit", "O'Fallon", "St. Joseph", "St. Charles", "Blue Springs"]
            ],
            [
                "name" => "Montana",
                "code" => "MT",
                "cities" => ["Unknown", "Billings", "Missoula", "Great Falls", "Bozeman", "Butte", "Helena", "Kalispell", "Havre", "Anaconda", "Miles City"]
            ],
            [
                "name" => "Nebraska",
                "code" => "NE",
                "cities" => ["Unknown", "Omaha", "Lincoln", "Bellevue", "Grand Island", "Kearney", "Fremont", "Hastings", "Norfolk", "Columbus", "Papillion"]
            ],
            [
                "name" => "Nevada",
                "code" => "NV",
                "cities" => ["Unknown", "Las Vegas", "Henderson", "Reno", "North Las Vegas", "Sparks", "Carson City", "Fernley", "Elko", "Mesquite", "Boulder City"]
            ],
            [
                "name" => "New Hampshire",
                "code" => "NH",
                "cities" => ["Unknown", "Manchester", "Nashua", "Concord", "Derry", "Rochester", "Salem", "Dover", "Merrimack", "Londonderry", "Hudson"]
            ],
            [
                "name" => "New Jersey",
                "code" => "NJ",
                "cities" => ["Unknown", "Newark", "Jersey City", "Paterson", "Elizabeth", "Edison", "Woodbridge", "Lakewood", "Toms River", "Hamilton", "Trenton"]
            ],
            [
                "name" => "New Mexico",
                "code" => "NM",
                "cities" => ["Unknown", "Albuquerque", "Las Cruces", "Rio Rancho", "Santa Fe", "Roswell", "Farmington", "Clovis", "Hobbs", "Alamogordo", "Carlsbad"]
            ],
            [
                "name" => "New York",
                "code" => "NY",
                "cities" => ["Unknown", "New York City", "Buffalo", "Rochester", "Yonkers", "Syracuse", "Albany", "New Rochelle", "Mount Vernon", "Schenectady", "Utica"]
            ],
            [
                "name" => "North Carolina",
                "code" => "NC",
                "cities" => ["Unknown", "Charlotte", "Raleigh", "Greensboro", "Durham", "Winston-Salem", "Fayetteville", "Cary", "Wilmington", "High Point", "Concord"]
            ],
            [
                "name" => "North Dakota",
                "code" => "ND",
                "cities" => ["Unknown", "Fargo", "Bismarck", "Grand Forks", "Minot", "West Fargo", "Williston", "Dickinson", "Mandan", "Jamestown", "Wahpeton"]
            ],
            [
                "name" => "Ohio",
                "code" => "OH",
                "cities" => ["Unknown", "Columbus", "Cleveland", "Cincinnati", "Toledo", "Akron", "Dayton", "Parma", "Canton", "Youngstown", "Lorain"]
            ],
            [
                "name" => "Oklahoma",
                "code" => "OK",
                "cities" => ["Unknown", "Oklahoma City", "Tulsa", "Norman", "Broken Arrow", "Lawton", "Edmond", "Moore", "Midwest City", "Enid", "Stillwater"]
            ],
            [
                "name" => "Oregon",
                "code" => "OR",
                "cities" => ["Unknown", "Portland", "Salem", "Eugene", "Gresham", "Hillsboro", "Beaverton", "Bend", "Medford", "Springfield", "Corvallis"]
            ],
            [
                "name" => "Pennsylvania",
                "code" => "PA",
                "cities" => ["Unknown", "Philadelphia", "Pittsburgh", "Allentown", "Erie", "Reading", "Scranton", "Bethlehem", "Lancaster", "Harrisburg", "Altoona"]
            ],
            [
                "name" => "Rhode Island",
                "code" => "RI",
                "cities" => ["Unknown", "Providence", "Warwick", "Cranston", "Pawtucket", "East Providence", "Woonsocket", "Coventry", "Cumberland", "North Providence", "South Kingstown"]
            ],
            [
                "name" => "South Carolina",
                "code" => "SC",
                "cities" => ["Unknown", "Columbia", "Charleston", "North Charleston", "Mount Pleasant", "Rock Hill", "Greenville", "Summerville", "Sumter", "Goose Creek", "Hilton Head Island"]
            ],
            [
                "name" => "South Dakota",
                "code" => "SD",
                "cities" => ["Unknown", "Sioux Falls", "Rapid City", "Aberdeen", "Brookings", "Watertown", "Mitchell", "Yankton", "Pierre", "Huron", "Vermillion"]
            ],
            [
                "name" => "Tennessee",
                "code" => "TN",
                "cities" => ["Unknown", "Nashville", "Memphis", "Knoxville", "Chattanooga", "Clarksville", "Murfreesboro", "Franklin", "Jackson", "Johnson City", "Bartlett"]
            ],
            [
                "name" => "Texas",
                "code" => "TX",
                "cities" => ["Unknown", "Houston", "San Antonio", "Dallas", "Austin", "Fort Worth", "El Paso", "Arlington", "Corpus Christi", "Plano", "Laredo", "Lubbock", "Garland", "Irving", "Amarillo", "Grand Prairie"]
            ],
            [
                "name" => "Utah",
                "code" => "UT",
                "cities" => ["Unknown", "Salt Lake City", "West Valley City", "Provo", "West Jordan", "Orem", "Sandy", "Ogden", "St. George", "Layton", "Taylorsville"]
            ],
            [
                "name" => "Vermont",
                "code" => "VT",
                "cities" => ["Unknown", "Burlington", "South Burlington", "Rutland", "Barre", "Montpelier", "Winooski", "St. Albans", "Newport", "Vergennes", "Brattleboro"]
            ],
            [
                "name" => "Virginia",
                "code" => "VA",
                "cities" => ["Unknown", "Virginia Beach", "Norfolk", "Chesapeake", "Richmond", "Newport News", "Alexandria", "Hampton", "Roanoke", "Portsmouth", "Suffolk"]
            ],
            [
                "name" => "Washington",
                "code" => "WA",
                "cities" => ["Unknown", "Seattle", "Spokane", "Tacoma", "Vancouver", "Bellevue", "Kent", "Everett", "Renton", "Yakima", "Federal Way"]
            ],
            [
                "name" => "West Virginia",
                "code" => "WV",
                "cities" => ["Unknown", "Charleston", "Huntington", "Morgantown", "Parkersburg", "Wheeling", "Weirton", "Fairmont", "Martinsburg", "Beckley", "Clarksburg"]
            ],
            [
                "name" => "Wisconsin",
                "code" => "WI",
                "cities" => ["Unknown", "Milwaukee", "Madison", "Green Bay", "Kenosha", "Racine", "Appleton", "Waukesha", "Eau Claire", "Oshkosh", "Janesville"]
            ],
            [
                "name" => "Wyoming",
                "code" => "WY",
                "cities" => ["Unknown", "Cheyenne", "Casper", "Laramie", "Gillette", "Rock Springs", "Sheridan", "Green River", "Evanston", "Riverton", "Jackson"]
            ]
        ]
    ],
    [
        "name" => "Canada",
        "code" => "CAN",
        "currency" => "CAD",
        "phone_code" => "+1",
        "phone_national_length" => 10,
        "flag" => "🇨🇦.svg",
        "states" => [
            [
                "name" => "Unknown",
                "code" => "UNK",
                "cities" => ["Unknown"]
            ],
            [
                "name" => "Alberta",
                "code" => "AB",
                "cities" => ["Unknown", "Calgary", "Edmonton", "Red Deer", "Lethbridge", "St. Albert", "Medicine Hat", "Grande Prairie", "Airdrie", "Spruce Grove", "Leduc"]
            ],
            [
                "name" => "British Columbia",
                "code" => "BC",
                "cities" => ["Unknown", "Vancouver", "Surrey", "Burnaby", "Richmond", "Abbotsford", "Coquitlam", "Kelowna", "Saanich", "Delta", "Kamloops"]
            ],
            [
                "name" => "Manitoba",
                "code" => "MB",
                "cities" => ["Unknown", "Winnipeg", "Brandon", "Steinbach", "Thompson", "Portage la Prairie", "Winkler", "Selkirk", "Morden", "Dauphin", "The Pas"]
            ],
            [
                "name" => "New Brunswick",
                "code" => "NB",
                "cities" => ["Unknown", "Moncton", "Saint John", "Fredericton", "Dieppe", "Miramichi", "Edmundston", "Bathurst", "Campbellton", "Riverview", "Quispamsis"]
            ],
            [
                "name" => "Newfoundland and Labrador",
                "code" => "NL",
                "cities" => ["Unknown", "St. John's", "Mount Pearl", "Corner Brook", "Conception Bay South", "Paradise", "Grand Falls-Windsor", "Gander", "Portugal Cove-St. Philip's", "Torbay", "Happy Valley-Goose Bay"]
            ],
            [
                "name" => "Northwest Territories",
                "code" => "NT",
                "cities" => ["Unknown", "Yellowknife", "Hay River", "Inuvik", "Fort Smith", "Behchokǫ̀", "Fort Simpson", "Norman Wells", "Tuktoyaktuk", "Fort Providence", "Aklavik"]
            ],
            [
                "name" => "Nova Scotia",
                "code" => "NS",
                "cities" => ["Unknown", "Halifax", "Cape Breton", "Truro", "New Glasgow", "Glace Bay", "Dartmouth", "Sydney", "Amherst", "Kentville", "Bridgewater"]
            ],
            [
                "name" => "Nunavut",
                "code" => "NU",
                "cities" => ["Unknown", "Iqaluit", "Rankin Inlet", "Arviat", "Baker Lake", "Cambridge Bay", "Pond Inlet", "Igloolik", "Kugluktuk", "Pangnirtung", "Cape Dorset"]
            ],
            [
                "name" => "Ontario",
                "code" => "ON",
                "cities" => ["Unknown", "Toronto", "Ottawa", "Mississauga", "Brampton", "Hamilton", "London", "Markham", "Vaughan", "Kitchener", "Windsor", "Richmond Hill", "Oakville", "Burlington", "Oshawa", "Barrie"]
            ],
            [
                "name" => "Prince Edward Island",
                "code" => "PE",
                "cities" => ["Unknown", "Charlottetown", "Summerside", "Stratford", "Cornwall", "Montague", "Kensington", "Souris", "Alberton", "O'Leary", "Georgetown"]
            ],
            [
                "name" => "Quebec",
                "code" => "QC",
                "cities" => ["Unknown", "Montreal", "Quebec City", "Laval", "Gatineau", "Longueuil", "Sherbrooke", "Saguenay", "Lévis", "Trois-Rivières", "Terrebonne", "Saint-Jean-sur-Richelieu", "Repentigny", "Brossard", "Drummondville", "Saint-Jérôme"]
            ],
            [
                "name" => "Saskatchewan",
                "code" => "SK",
                "cities" => ["Unknown", "Saskatoon", "Regina", "Prince Albert", "Moose Jaw", "Swift Current", "Yorkton", "North Battleford", "Estevan", "Weyburn", "Martensville"]
            ],
            [
                "name" => "Yukon",
                "code" => "YT",
                "cities" => ["Unknown", "Whitehorse", "Dawson City", "Watson Lake", "Haines Junction", "Carmacks", "Mayo", "Faro", "Teslin", "Ross River", "Pelly Crossing"]
            ]
        ]
    ]
];
